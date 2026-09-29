{**
 * Dashboard card: what we sell from a supplier, per sold country. Shared by
 * the Novoton, Eurosite and Sphinx dashboards; each links to its own
 * destination page. Params: card =
 *   id, title, intro, edit_url
 *   cols   [{label}]                   the add-on's figure columns
 *   rows   [{label, badge, badge_class, words, cells: [{value, warn}], new}]
 *   off    countries not sold (one line under the table)
 *}
{style src="addons/travel_core/destination-picker.css"}
{$_card = $card}
<section class="travel-cron-card travel-cron-card--flush travel-dest-card" id="{$_card.id}" aria-labelledby="{$_card.id}-title">
    <div class="travel-cron-card__head travel-cron-card__head--pad">
        <div>
            <h3 id="{$_card.id}-title">{$_card.title}</h3>
            <p class="muted">{$_card.intro}</p>
        </div>
        <a class="btn" href="{$_card.edit_url}">{__("travel_core.dest_card_edit")}</a>
    </div>
    <table class="table table-middle travel-cron-table travel-dest-card__table">
        <thead>
            <tr>
                <th>{__("travel_core.dest_card_col_country")}</th>
                <th>{__("travel_core.dest_card_col_sell")}</th>
                {foreach $_card.cols as $_col}<th class="right">{$_col.label}</th>{/foreach}
                <th>{__("travel_core.dest_card_col_review")}</th>
            </tr>
        </thead>
        <tbody>
            {foreach $_card.rows as $_row}
                <tr>
                    <td><strong>{$_row.label|escape:html}</strong></td>
                    <td><span class="travel-dest-badge travel-dest-badge--{$_row.badge_class}">{$_row.badge|escape:html}</span> {$_row.words|escape:html}</td>
                    {foreach $_row.cells as $_cell}
                        <td class="right">{if $_cell.warn}<strong class="travel-cron-hint--warn">{$_cell.value}</strong>{else}{$_cell.value}{/if}</td>
                    {/foreach}
                    <td>{if $_row.new > 0}<span class="travel-dest-badge travel-dest-badge--new">{__("travel_core.dest_badge_new_n", ["[n]" => $_row.new])}</span>{else}<span class="muted">—</span>{/if}</td>
                </tr>
            {foreachelse}
                <tr><td colspan="{$_card.cols|count + 3}" class="muted">{__("travel_core.dest_card_none")}</td></tr>
            {/foreach}
        </tbody>
    </table>
    {if $_card.off > 0}<p class="travel-cron-foot muted">{__("travel_core.dest_card_off", ["[n]" => $_card.off])}</p>{/if}
</section>
