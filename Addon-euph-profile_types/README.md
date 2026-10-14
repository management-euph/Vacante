# `Addon-euph-profile_types/` — Profile types for customers and vendors

CS-Cart add-on **`euph_profile_types`** 2.2.0, by VacanteLitoral.ro
(office@VacanteLitoral.ro, https://vacantelitoral.ro).

Customers belong to a **profile type**: Private person, Company, Travel agency, and so on. In Multi-Vendor, vendors do too.
Each type has its own profile fields, user groups, storefronts and activation rule.
The store keeps **one common list of profile fields**. For each type, the add-on decides where a field shows and whether it is required.

- **Editions:** CS-Cart, CS-Cart Ultimate, Multi-Vendor, Multi-Vendor Plus/Ultimate, **4.21.2 or newer**.
- **PHP:** **8.3** or newer.
- **Dependencies:** none. There is no add-ons manager and no license key.
- **Origin:** a redesign of Cart-Power's `cp_profile_types` 1.5. The code is new, and every name and table uses `euph_`. The 1.x data is imported at install (see [Moving from 1.x](#moving-from-cp_profile_types-1x)).

## Screens

Mockups of the main screens. The numbered marks refer to the notes under each image. The sources and the render script are in [`docs/mockups/src/`](docs/mockups/src/).

<table>
<tr>
<td><a href="docs/mockups/admin-01-profile-types.png"><img src="docs/mockups/admin-01-profile-types.png" width="260" alt="List of customer profile types"></a><br>List of types</td>
<td><a href="docs/mockups/admin-02-type-card-general.png"><img src="docs/mockups/admin-02-type-card-general.png" width="260" alt="Type card, General tab"></a><br>Type card: General, registration links</td>
<td><a href="docs/mockups/admin-03-type-card-rules.png"><img src="docs/mockups/admin-03-type-card-rules.png" width="260" alt="Type card, Rules tab"></a><br>Type card: groups, activation, storefronts</td>
</tr>
<tr>
<td><a href="docs/mockups/admin-04-type-card-fields.png"><img src="docs/mockups/admin-04-type-card-fields.png" width="260" alt="Type card, Fields tab"></a><br>Type card: the fields of the type</td>
<td><a href="docs/mockups/admin-05-profile-field-card.png"><img src="docs/mockups/admin-05-profile-field-card.png" width="260" alt="Profile field card with the Profile types block"></a><br>Profile field card: a row per type</td>
<td><a href="docs/mockups/admin-06-customer-card.png"><img src="docs/mockups/admin-06-customer-card.png" width="260" alt="Customer card"></a><br>Customer card</td>
</tr>
<tr>
<td><a href="docs/mockups/admin-07-order-details.png"><img src="docs/mockups/admin-07-order-details.png" width="260" alt="Order details"></a><br>Order: the type is the first sidebar block</td>
<td><a href="docs/mockups/admin-08-settings.png"><img src="docs/mockups/admin-08-settings.png" width="260" alt="Add-on settings"></a><br>Settings</td>
<td><a href="docs/mockups/storefront-01-registration.png"><img src="docs/mockups/storefront-01-registration.png" width="260" alt="Registration by type"></a><br>Registration: Company vs Private person</td>
</tr>
<tr>
<td><a href="docs/mockups/storefront-02-checkout.png"><img src="docs/mockups/storefront-02-checkout.png" width="260" alt="Checkout with the type choice"></a><br>Checkout: type choice, invoice details</td>
<td><a href="docs/mockups/storefront-03-order-details.png"><img src="docs/mockups/storefront-03-order-details.png" width="260" alt="Order details in the customer account"></a><br>Order in the customer account</td>
<td><a href="docs/mockups/storefront-04-vendor-types.png"><img src="docs/mockups/storefront-04-vendor-types.png" width="260" alt="Vendor types"></a><br>Multi-Vendor: vendor types and plans</td>
</tr>
<tr>
<td><a href="docs/mockups/admin-09-fields-all-types.png"><img src="docs/mockups/admin-09-fields-all-types.png" width="260" alt="Profile fields, all types"></a><br>Profile fields: all types</td>
<td><a href="docs/mockups/admin-10-fields-one-type.png"><img src="docs/mockups/admin-10-fields-one-type.png" width="260" alt="Profile fields, one type"></a><br>Profile fields: one type</td>
<td><a href="docs/mockups/storefront-05-pills.png"><img src="docs/mockups/storefront-05-pills.png" width="260" alt="Type choice as pills"></a><br>Type choice as pills (<a href="docs/mockups/storefront-06-pills-phone.png">phone</a>)</td>
</tr>
</table>

The last row is rendered from the add-on's own styles and script on the markup its templates draw (`docs/mockups/src/live.mjs`); only the admin chrome around it is drawn.

## What it does

### Profile types

- **Where:** *Customers → Customer profile types*. In Multi-Vendor, also *Vendors → Vendor profile types*.
- **Standard types:** install creates *Customer* (code `U`) and *Vendor* (code `S`). They cannot be deleted, and their codes do not change. Deleting any other type moves its customers or vendors to the standard type. Their orders keep the type they were placed with.
- **Code:** Latin capitals, digits and `_`, up to 32 characters. It is stored with customers, vendors and orders, and used in registration links. Renaming a code carries the new code over to everything that had the old one.
- **Status:**
  - *Active*: offered in the choice.
  - *Hidden*: not offered. A customer gets it only through its registration link or from an administrator: knowing the code is not enough.
  - *Disabled*: nobody new gets it. Those who have it keep it.
- **Default type:** what guests and new accounts get when nobody chose a type.
- **Rules** (one tab of the type card):
  - **user groups** the type grants;
  - **storefronts** where the type works (none ticked means all);
  - **account activation** for new storefront accounts: *as the store setting says*, *at once* or *after approval*;
  - for vendor types, the **vendor plans** an applicant of the type may choose.
- **Registration links**, shown on the type card:
  - `index.php?dispatch=profiles.add&euph_profile_type=B&euph_profile_key=…` preselects the type in the registration form.
  - Adding `&euph_profile_type_locked=1` stops the visitor from changing it.
  - The vendor application works the same way: `index.php?dispatch=companies.apply_for_vendor&euph_profile_type=…&euph_profile_key=…`.
  - `euph_profile_key` is the type's secret (128 random bits). A hidden type is given only to a visitor who opened its link: in the registration form, a change of type in the profile, at checkout and in the vendor application. Its code alone does not show it. An active type needs no key.
  - *New links* on the type card gives the type a new key on saving: the links sent so far stop working, and those who already have the type keep it.

### Profile fields per type

Each type has these checkboxes for every profile field:

| Customer types | Vendor types |
|---|---|
| In the profile: show / required | In the profile: show / required |
| At registration: show | In the application: show |
| At checkout: show / required | On the storefront (vendor page): show |

They can be ticked in three places, all on the same data:

- **CS-Cart's own Profile fields list.** A switcher above the list shows *All types* or one type at a time:
  - *All types*: a field set per type shows a row per type, the type named once with its colour. A field still on its general checkboxes takes **one line**: CS-Cart's own checkboxes, for all types. **Set per type** opens its rows, filled from those checkboxes. The e-mail stays always shown, and a shipping field follows its billing twin.
  - *One type*: one line per field, the form that type gets. Fields the type does not see are greyed. Ticking a general field here sets it per type; the other types keep what they saw.
  - Everything is saved with the list's own **Save**. The view is remembered, and a type card opens the list on its type.
  - The list's own template is not replaced: the rows hang on its hook in each Show / Required cell.
- **The field card:** a **Profile types** block with one row per type.
- **The type card:** a **Fields** tab with one row per field.

**How a field is shown:**

- As soon as something is ticked for a field for any type, the types **govern** that field. From then on, each type sees it only where its own checkboxes say so.
- While the add-on is on, the field's **general checkboxes** (CS-Cart's own *Profile* and *Checkout* show/required) no longer decide anything for that field. The card says so.
- A field with **nothing ticked for any type** works by its general checkboxes, as fields always did. That covers fields created before the add-on, and any field you don't need to split by type.
- Two helpers on the card: *Same as the general checkboxes* and *Clear*. Clearing every row gives the field back to its general checkboxes.
- When a field first becomes governed, the types you did not tick for it are filled in from the general checkboxes. They keep seeing exactly what they saw before.

The list of fields stays one list. A value survives a change of type, and reports and exports see one and the same field.

### Storefront: registration, profile, checkout

- The type choice is the **first field of the Contact information section**. It shows as radio buttons, a drop-down or **tabs (pills)**, by setting.
  - Tabs draw the choice as a segmented control. Underneath they stay radio buttons, so arrow keys, screen readers and the form redraw keep working; without the script the customer sees plain radio buttons.
  - A type is always selected: the type of the registration link, else the customer's own, else the default type.
- **Changing the type redraws the form** with the fields of the new type, without reloading, and keeps what was typed. The *Reload the page* setting switches this to a reload, for a captcha or another script that does not survive the redraw. The typing is put back after the reload; passwords are not.
- **Checkout:**
  - At install, the *Customer information*, *Shipping address* and *Billing address* blocks of the checkout get the **By profile type** filling.
  - Guests choose the type right there. Signed-in customers can too, by setting.
  - Without the choice, a signed-in customer orders with the type of their profile.
- **Changing type after registration:** *Always*, *One change* or *Never*. An administrator can always change it in the panel.
- **User groups follow the type.** A customer who gets a type joins its groups. On a change of type, the customer leaves the old type's groups and joins the new type's. The new groups get the *Group status after changing profile type* (Active or Pending).
- **Storefronts:** with *Let customers sign in only on the storefronts of their profile type* on, signing in on another storefront is refused with a message, and a session carried over from another storefront is signed out.

### Orders, documents, e-mails, exports

- The order keeps the type it was placed with. In the admin order page, the **profile type is the first sidebar block**, and the type of that order alone can be corrected there.
- The customer information of an order shows the fields of the order's type: in the panel, in the order details of the customer account, in the documents and in the e-mails.
- In **Design → Documents** (invoice, order summary, e-mails), the `user` variable carries:
  - the fields of the order's type, by field code: `{{ user.company_cui }}`;
  - for state and country, the name under `_descr`;
  - the type: `{{ user.profile_type }}` (name) and `{{ user.profile_type_code }}`.

  The stock invoice prints only the fields CS-Cart knows about, so add your own fields to it once.
- **Exports:** *Profile type* is a field of the Customers and Orders exports.
- **Lists:** the Customers list (and the Vendors list) get a *Profile type* column and a search filter.

### Russian Payments: "Issue an invoice"

With *Do not ask again for invoice details taken from the profile* on:

- the invoice payment's details that the customer's profile already holds are filled in, and their inputs are hidden;
- one line under the payment says what was taken.

Which details are taken depends on the profile fields mapped in the payment method's settings.

### Multi-Vendor

- Vendors get their own types, with their own fields, user groups and vendor plans.
- The type is chosen in the vendor application.
- With *Use the name of the profile type in the vendor information* on, the vendor's type shows next to the vendor's name on the product page.

## Settings

*Add-ons → Profile types for customers and vendors → Settings*. In CS-Cart Ultimate, the settings are per storefront.

| Setting | Values (default first) | What it does |
|---|---|---|
| Use profile types for | Customers and vendors · Customers · Vendors | Vendor types work in Multi-Vendor only. |
| Allow user to change type after registration | Never · Always · One change | Applies on the storefront. The administrator can always change the type. |
| Group status after changing profile type | Active · Pending | Status of the groups of the new type. |
| Hide standard types from the choice on the storefront | off | Customers choose only from the types you created, if at least one of them is active. |
| Profile type choice view | Radio buttons · Drop-down · Tabs (pills) | Tabs suit two to four types with short names; with more types the drop-down reads better. |
| Reload the page when the type is changed | off | By default the form is redrawn in place. |
| Let customers sign in only on the storefronts of their profile type | off | See *Storefronts* above. |
| Profile type choice at checkout | Everybody · Guests only · Nobody | |
| Do not ask again for invoice details taken from the profile | off | Russian Payments, *Issue an invoice*. |
| Use the name of the profile type in the vendor information | off | Multi-Vendor. |

**Privileges:** *View profile types* and *Manage profile types* are in the *Add-ons* group of the administrator user groups.

## Install, disable, uninstall

**Install** (upload the zip as a manual add-on installation, or copy its folders to the store root):

- creates the add-on's tables: `?:euph_profile_types`, `?:euph_profile_type_descriptions`, `?:euph_profile_type_fields` (the per-type checkboxes) and `?:euph_profile_type_links` (groups, storefronts, plans);
- adds the type columns: `users.euph_profile_type`, `users.euph_profile_type_changes`, `orders.euph_profile_type`, and in Multi-Vendor `companies.euph_profile_type`;
- creates the two standard types and gives everybody without a type the standard one;
- gives every type the key of its registration links (a store that already runs an earlier version gets the keys when *Profile types* is opened);
- imports the 1.x data, if present;
- switches the checkout blocks to the *By profile type* filling.

**Theme:** the storefront templates are written for `responsive`.

- `scripts/deploy-addons.sh --addons euph_profile_types` also copies them into `nova_theme`, the production theme.
- When the zip is installed instead, `nova_theme` reaches them through its fallback to `responsive`.

**Disable:**

- the storefront is back to stock behaviour: CS-Cart's general checkboxes apply to every field, and there is no type choice;
- the checkout blocks get back the filling they had;
- nothing is deleted.

**Re-enable** restores everything, the checkout block fillings included.

**Uninstall:**

- drops the add-on's four tables and the type columns of customers, orders and vendors (the type of each customer, vendor and order goes with them);
- removes its two privileges and their grants to administrator user groups;
- gives the checkout blocks back the filling they had; CS-Cart removes the add-on's settings and texts;
- **keeps the profile fields**, their values and their general checkboxes. The add-on never creates or deletes a field nor writes its checkboxes, and replaces no CS-Cart template: the *Profile fields* list is CS-Cart's own again, each field working by its own checkboxes;
- keeps what belongs to CS-Cart: the customers' user groups, and the fields and values imported from 1.x.

Before uninstalling:

- **disable** the add-on first: the list then shows each field's own checkboxes, which is what every customer gets after the uninstall; adjust them there;
- export the customers and orders with their *Profile type* column if you want to keep a record of the types;
- take the add-on's placeholders (`{{ user.profile_type }}`…) out of the document and e-mail templates you added them to.

## Moving from `cp_profile_types` 1.x

If the 1.x tables (`?:cp_profile_types…`) are in the database, install imports their data. The 1.x add-on itself does not need to be installed.

**What is imported:**

- the types, their names and descriptions, user groups, storefronts and vendor plans;
- the type of customers, orders and vendors (codes are upper-cased);
- the per-type copies of profile fields that 1.x created. Each copy is matched to the common field with the same section and code: its checkboxes become that field's checkboxes for the type, and its values are copied to the common field. A choice field's values are translated by the position of the variant. A copy with no common twin becomes a common field.

The import deletes nothing and leaves the 1.x tables as they are. The only existing rows it changes are field copies with no common twin, which become common fields. While the 1.x tables are in the database, a notice at the top of the list of types offers to run the import again.

### What the new names change

Every `cp_` name is gone: tables, columns, functions, hooks, request parameters, language variables, privileges. Data is not affected, because the import reads the old tables by their old names. Only **code or content outside the add-on** that used the old names needs the new ones:

| 1.x | 2.2 |
|---|---|
| `fn_cp_get_profile_types($params)` | `fn_euph_get_profile_types($params)` |
| `fn_cp_get_profile_type_data($type_id, $lang_code)` | `fn_euph_get_profile_type_data($type_id, $lang_code)` |
| `fn_cp_update_profile_type(…)` | `fn_euph_update_profile_type($type_id, $type_data, $lang_code)` |
| `fn_cp_delete_profile_type($type_id)` | `fn_euph_delete_profile_type($type_id)` |
| hooks `cp_update_profile_type_pre` / `_post`, `cp_get_profile_type_data_post`, `cp_delete_profile_type` | `euph_update_profile_type_pre` / `_post`, `euph_get_profile_type_data_post`, `euph_delete_profile_type` |
| hooks `cp_get_profile_types_pre`, `cp_get_profile_types`, `cp_get_profile_types_post` | no equivalent: work on the result of `fn_euph_get_profile_types()` |
| columns `cp_profile_type` of users, orders, companies | `euph_profile_type` |
| links `…&cp_profile_type=B` | `…&euph_profile_type=B` (`&euph_profile_type_locked=1` locks it) |

Things to check:

- **Another add-on** calling the old functions or hooks, such as a shipping or payment restriction by profile type, must use the names above. One that checks `function_exists('fn_cp_…')` just stops seeing the types.
- **Links** with `cp_profile_type=` in pages, banners or e-mails must be updated.
- **Documents and e-mail templates:** the variables are `{{ user.<field code> }}`, as above.
- **Not carried over from 1.x:** the *Vendor type* product filter of Multi-Vendor.

## For developers

### Integration functions

All are in `functions/types.php`.

| Function | Returns |
|---|---|
| `fn_euph_get_profile_types($params)` | Types by id. Params: `user_type` (C/V), `status` (A/H/D or a list), `code`, `storefront_id`, `lang_code`. |
| `fn_euph_get_profile_type_data($type_id, $lang_code)` | A type with its `usergroup_ids`, `storefront_ids`, `vendor_plan_ids`. |
| `fn_euph_update_profile_type($type_id, $type_data, $lang_code)` | Creates (id 0) or updates a type. Returns its id, or 0 with the reasons notified. |
| `fn_euph_delete_profile_type($type_id)` | Deletes a type (not a standard one). |
| `fn_euph_profile_types_get_current_customer_type()` | Code of the current customer's type, a guest at checkout included. |
| `fn_euph_profile_types_get_selectable_types($user_type, $current_code)` | `code => name` of the choice on this storefront. |
| `fn_euph_profile_types_is_valid_type_code($code, $user_type)` | Whether a code exists and can be given. |
| `fn_euph_profile_types_is_code_on_storefront($code, $storefront_id)` | Whether a type works on a storefront. |
| `fn_euph_profile_types_get_type_name($code, $lang_code)` | The name of a type; the code if the type is gone. |

### Hooks

| Hook | Arguments |
|---|---|
| `euph_update_profile_type_pre` | `$type_data, $type_id, $lang_code` |
| `euph_update_profile_type_post` | `$type_data, $type_id, $lang_code` |
| `euph_get_profile_type_data_post` | `$type_id, $lang_code, $type_data` |
| `euph_delete_profile_type` | `$type_id, $type_code` |
| `euph_profile_types_update_profile` | `$action, $user_data, $type_code, $previous_type_code` (after a profile is saved) |

Register handlers as usual: `fn_register_hooks('euph_update_profile_type_post', …)`.

### How it hooks into CS-Cart

- **No core template is overridden.**
- The type choice is a virtual profile field (`euph_profile_type`), so the stock profile, registration and checkout templates draw it.
- The Profile fields list gets its per-type rows through its own hooks: `profile_fields:field_settings` (each Show / Required cell) and `profile_fields:field_type_description` (the Type column). The field card uses `profile_fields:profile_data`.
- Fields are filtered in the `get_profile_fields` / `get_profile_fields_post` hooks.
- The checkout uses its own block filling.
- The rest goes through hooks on users, companies and orders, pre/post controllers, template hooks, and the `block_manager`, `documents`, `exim`, `menu` and `permissions` schemas.

### Code layout

```
Addon-euph-profile_types/
├── app/addons/euph_profile_types/
│   ├── addon.xml            id, settings, tables, privileges
│   ├── init.php, func.php   hook registration; func.php only loads functions/ and hooks/
│   ├── functions/           the CS-Cart boundary: runtime, field context, types API, users,
│   │                        vendors, orders and documents, install/uninstall, admin helpers
│   ├── hooks/               the PHP hook handlers (fields, users, vendors, orders, add-on status)
│   ├── controllers/         backend: types, profile fields, customers, vendors, orders, order editor
│   │                        frontend: profiles, checkout, orders, vendor application, sign-in
│   ├── schemas/             block_manager, documents, exim, menu, permissions
│   ├── src/                 typed domain (no CS-Cart calls): Model, Repository, Service, Enum, Db
│   └── tests/               PHPUnit (unit, with an in-memory fake of the database)
├── design/backend/…         type list and card, Profile fields list by type, field card block,
│                            list columns, order sidebar
├── design/themes/responsive/…  scripts, styles, vendor type badge
├── js/addons/euph_profile_types/func.js   form redraw, pills, invoice details, Profile fields
│                                          list by type, admin helpers
├── var/langs/{en,ro}/addons/euph_profile_types.po
└── docs/mockups/            the screens above and their sources (not packaged)
```

### Checks

All of them run in CI (`.github/workflows/ci.yml`):

| Check | Command |
|---|---|
| PHPUnit (CI also measures coverage, floor 88 %) | `composer test:euph` |
| PHPStan level 10, strict | `composer stan` |
| Code style: `src/` PSR-12, the rest by the procedural config | `composer fix:dry` |
| Rector | `composer rector:dry` |
| ESLint | `npx eslint Addon-euph-profile_types/js` |
| Vitest (jsdom) | `npx vitest run tests/js/euph-profile-types.test.mjs` |

Other commands:

- **Package:** `php scripts/package-addons.php euph_profile_types` writes `dist/euph_profile_types-2.2.0.zip`. Only app, design, js and the language packs are packed; tests, vendor and docs are left out.
- **Mockups:**
  - `node Addon-euph-profile_types/docs/mockups/src/render.mjs` draws the static mockups. It needs the `playwright` package; set `PLAYWRIGHT_MODULE` to its path if it is installed globally.
  - `node Addon-euph-profile_types/docs/mockups/src/live.mjs` renders the screens of the Profile fields list and of the pills from the add-on's own LESS and script. It also needs the `less` package (`LESS_MODULE`).

## Before going live: test on a copy of the store

The code is checked by static analysis and unit tests, but it was not run inside a CS-Cart 4.21.2 store. Click through these on a staging copy (with Multi-Vendor, the vendor items too):

1. **Install** the add-on, on the store's own theme (`nova_theme` in production).
   - The *Customer profile types* menu item, the two standard types and the settings appear.
   - The checkout blocks show the *By profile type* filling in *Design → Layouts → Checkout*.
2. **Create a type**, for example Company `B`: tick its fields on the *Fields* tab, give it a user group, set *Wait for approval*.
3. **Open Profile fields.**
   - The switcher appears above CS-Cart's list. Try *All types* and each type.
   - Save the list without touching anything: no field may become "per type".
   - Use *Set per type* on a field, tick one type differently and save. The other types keep what they saw.
   - Open a field card: check the *Profile types* block and its note.
   - If the switcher does not show, the list's hooks are not where this add-on expects them in your CS-Cart version. The field card and the type card still work.
4. **Register on the storefront.**
   - Switch the type: the form must redraw with the right fields and keep the typing. This also shows that the add-on's scripts load on the theme.
   - Try both links: open and locked.
   - Make the type hidden: its link still works, the same address without `euph_profile_key` (or with a wrong one) shows only the offered types, and after *New links* the old link no longer works.
   - The account must wait for approval, and the user group must be granted.
5. **Profile:** try changing the type under each *Allow user to change type* value.
   - After each change, on the storefront and in the admin customer card, the customer must have left the old type's user groups and joined the new type's.
   - Set *Profile type choice view* to *Tabs (pills)* and check the choice on registration, the profile and checkout: on a phone as well.
6. **Checkout**, as a guest and signed in:
   - the type choice;
   - the required fields of each type;
   - the order's type in the admin sidebar, in the customer's order page, the invoice and the order e-mail.
7. **Documents:** add `{{ user.<your field code> }}` and `{{ user.profile_type }}` to the invoice and print it.
8. **Admin:**
   - customer card: the type change redraws the card;
   - Customers list: column and filter;
   - Customers and Orders export: *Profile type*;
   - order editor: the type of a new order.
9. **Storefronts** (Ultimate / MV Plus/Ultimate): tie a type to one storefront, turn on the sign-in rule, and sign in on another storefront.
10. **Russian Payments** (if used): turn on *Do not ask again for invoice details…* and check the *Issue an invoice* payment at checkout.
11. **Multi-Vendor:** vendor types in the application, plans by type, the type next to the vendor name.
12. **Disable** the add-on: stock profile and checkout forms, and the checkout blocks back to their filling. **Re-enable**: everything back. **Uninstall** on the copy: tables and type columns gone, profile fields still there.
