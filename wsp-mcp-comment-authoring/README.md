# WSP MCP Comment Authoring

This companion plugin adds two MCP tools to every WordPress site where it is installed alongside WSP MCP:

- `wsp_comment_authoring_create`: creates a top-level comment with a supplied author name and optional real email address.
- `wsp_comment_authoring_reply`: replies to an existing comment as the authenticated WordPress editor.

Both tools require the WSP MCP **Approve Comment** switch to be enabled and the connected WordPress account to have `moderate_comments` and `edit_post` permission for the target. New top-level comments require an open comment form on a published, publicly viewable post or page. Replies can be added after the form is closed. The caller must explicitly choose whether to approve each comment.

Install the zip through **Plugins → Add New → Upload Plugin** on each site. Activate WSP MCP first, then activate this companion. Reconnect the MCP client if it caches the tool list.

Author names should reflect real reader submissions or clearly identified editorial examples. The plugin records the acting WordPress user in private comment metadata for audit purposes.