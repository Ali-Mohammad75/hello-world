<?php
/**
 * Plugin Name: WSP MCP Comment Authoring
 * Description: Adds permission-checked MCP tools to create WordPress comments and reply to existing comments on sites using WSP MCP.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: wsp-mcp-ai-agents-connector
 * Author: Click Tahvieh
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

final class WSP_MCP_Comment_Authoring {
	private const MAX_NAME_LENGTH = 120;
	private const MAX_CONTENT_LENGTH = 5000;

	public static function bootstrap() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_tools' ), 20 );
	}

	public static function register_tools() {
		if ( ! class_exists( 'WSP_MCP_Server' ) ) {
			return;
		}

		$common = array(
			'post_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
			'content'  => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_CONTENT_LENGTH ),
			'approved' => array( 'type' => 'boolean', 'description' => 'true publishes immediately; false saves for moderation.' ),
		);

		WSP_MCP_Server::register_tool(
			'wsp_comment_authoring_create',
			array(
				'description' => 'Create a top-level comment for an existing published post or page. Use an author name only for a genuine reader submission or a clearly identified editorial example. Requires comment moderation permission. Returns the saved comment ID and status.',
				'inputSchema' => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'author_name', 'content', 'approved' ),
					'additionalProperties' => false,
					'properties'           => $common + array(
						'author_name'  => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_NAME_LENGTH ),
						'author_email' => array( 'type' => 'string', 'maxLength' => 254, 'description' => 'Optional real email address supplied by the commenter; never fabricate an address.' ),
					),
				),
				'callback'   => array( __CLASS__, 'create' ),
				'capability' => 'moderate_comments',
				'enable_key' => 'wsp/approve-comment',
			)
		);

		WSP_MCP_Server::register_tool(
			'wsp_comment_authoring_reply',
			array(
				'description' => 'Reply as the authenticated site editor to an existing WordPress comment. The parent comment must belong to the given published post or page. Requires comment moderation permission.',
				'inputSchema' => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'parent_id', 'content', 'approved' ),
					'additionalProperties' => false,
					'properties'           => $common + array(
						'parent_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'callback'   => array( __CLASS__, 'reply' ),
				'capability' => 'moderate_comments',
				'enable_key' => 'wsp/approve-comment',
			)
		);
	}

	private static function validate_post( $post_id, $require_open = true ) {
		$post_id = absint( $post_id );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			return new WP_Error( 'invalid_target_post', 'The target must be a published, publicly viewable post or page.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'comment_permission_denied', 'You cannot manage comments on this post.' );
		}
		if ( $require_open && ! comments_open( $post_id ) ) {
			return new WP_Error( 'comments_closed', 'Comments are closed on this post.' );
		}
		return $post;
	}

	private static function text_length( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}
		if ( function_exists( 'iconv_strlen' ) ) {
			return iconv_strlen( $value, 'UTF-8' );
		}
		return preg_match_all( '/./us', $value );
	}

	private static function validate_content( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_CONTENT_LENGTH * 4 ) {
			return new WP_Error( 'invalid_comment_content', 'Comment content is missing or too long.' );
		}
		$content = trim( sanitize_textarea_field( $value ) );
		if ( '' === $content || self::text_length( $content ) > self::MAX_CONTENT_LENGTH ) {
			return new WP_Error( 'invalid_comment_content', 'Comment content is missing or too long.' );
		}
		return $content;
	}

	private static function validate_approved( $input ) {
		if ( ! array_key_exists( 'approved', $input ) || ! is_bool( $input['approved'] ) ) {
			return new WP_Error( 'invalid_approval', 'approved must be explicitly true or false.' );
		}
		return $input['approved'] ? 1 : 0;
	}

	private static function insert( $post_id, $parent_id, $name, $email, $content, $approved, $user_id ) {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_parent'       => $parent_id,
				'comment_author'       => wp_slash( $name ),
				'comment_author_email' => $email,
				'comment_content'      => wp_slash( $content ),
				'comment_approved'     => $approved,
				'comment_type'         => 'comment',
				'user_id'              => $user_id,
				'comment_meta'         => array(
					'_wsp_mcp_comment_authoring_actor' => get_current_user_id(),
					'_wsp_mcp_comment_authoring_version' => '1.0.0',
				),
			)
		);
		if ( ! $comment_id ) {
			return new WP_Error( 'comment_insert_failed', 'WordPress could not save the comment.' );
		}
		$comment = get_comment( $comment_id );
		return array(
			'success'   => true,
			'id'        => (int) $comment_id,
			'post_id'   => (int) $post_id,
			'parent_id' => (int) $parent_id,
			'author'    => $comment->comment_author,
			'status'    => '1' === (string) $comment->comment_approved ? 'approved' : 'pending',
			'link'      => '1' === (string) $comment->comment_approved ? get_comment_link( $comment_id ) : '',
		);
	}

	public static function create( $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post = self::validate_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$name = isset( $input['author_name'] ) && is_string( $input['author_name'] ) ? trim( sanitize_text_field( $input['author_name'] ) ) : '';
		if ( '' === $name || self::text_length( $name ) > self::MAX_NAME_LENGTH ) {
			return new WP_Error( 'invalid_author_name', 'A valid author_name is required.' );
		}
		$email = '';
		if ( isset( $input['author_email'] ) && '' !== $input['author_email'] ) {
			if ( ! is_string( $input['author_email'] ) || ! is_email( $input['author_email'] ) ) {
				return new WP_Error( 'invalid_author_email', 'author_email must be a valid email address.' );
			}
			$email = sanitize_email( $input['author_email'] );
		}
		$content = self::validate_content( isset( $input['content'] ) ? $input['content'] : null );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		$approved = self::validate_approved( $input );
		if ( is_wp_error( $approved ) ) {
			return $approved;
		}
		return self::insert( $post_id, 0, $name, $email, $content, $approved, 0 );
	}

	public static function reply( $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post = self::validate_post( $post_id, false );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$parent_id = isset( $input['parent_id'] ) ? absint( $input['parent_id'] ) : 0;
		$parent = $parent_id ? get_comment( $parent_id ) : null;
		if ( ! $parent || (int) $parent->comment_post_ID !== $post_id || ! in_array( $parent->comment_type, array( '', 'comment' ), true ) ) {
			return new WP_Error( 'invalid_parent_comment', 'The parent must be a comment on the target post.' );
		}
		$content = self::validate_content( isset( $input['content'] ) ? $input['content'] : null );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		$approved = self::validate_approved( $input );
		if ( is_wp_error( $approved ) ) {
			return $approved;
		}
		if ( 1 === $approved && '1' !== (string) $parent->comment_approved ) {
			return new WP_Error( 'parent_not_approved', 'Approve the parent comment before publishing a reply.' );
		}
		$user = wp_get_current_user();
		$name = $user && $user->exists() ? $user->display_name : get_bloginfo( 'name' );
		$email = $user && $user->exists() ? $user->user_email : '';
		return self::insert( $post_id, $parent_id, $name, $email, $content, $approved, get_current_user_id() );
	}
}

WSP_MCP_Comment_Authoring::bootstrap();