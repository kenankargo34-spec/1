(function (wp) {
  if (!wp) return;
  // Map deprecated wp.editPost.PluginSidebar* to wp.editor.PluginSidebar*
  try {
    if (wp.editor && wp.editPost) {
      if (wp.editPost.PluginSidebar && !wp.editor.PluginSidebar) {
        wp.editor.PluginSidebar = wp.editPost.PluginSidebar;
      }
      if (wp.editPost.PluginSidebarMoreMenuItem && !wp.editor.PluginSidebarMoreMenuItem) {
        wp.editor.PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
      }
    }
  } catch (e) {
    // Silent fail – compatibility shim should never break editor
  }
})(window.wp || {});
