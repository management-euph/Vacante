{**
 * Novoton Holidays - Admin Panel JavaScript Connection Hook
 *
 * Connects addon JS files for the admin panel.
 * Location: design/backend/templates/addons/novoton_holidays/hooks/index/scripts.post.tpl
 *}

{* The Destinations page's script (travel_core/destination-picker.js) is
   loaded by the page itself, inside its mainbox capture: from here it would
   only run on a full page load, never after the admin's AJAX navigation. *}
{script src="js/addons/novoton_holidays/func.js"}
