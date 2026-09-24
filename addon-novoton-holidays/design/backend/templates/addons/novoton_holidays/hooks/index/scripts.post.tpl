{**
 * Novoton Holidays - Admin Panel JavaScript Connection Hook
 *
 * Connects addon JS files for the admin panel.
 * Location: design/backend/templates/addons/novoton_holidays/hooks/index/scripts.post.tpl
 *}

{* resort-manager.js is loaded by the dashboard itself, inside its mainbox
   capture: from here it only ran on a full page load, never after the
   admin's AJAX navigation to the dashboard. *}
{script src="js/addons/novoton_holidays/func.js"}
