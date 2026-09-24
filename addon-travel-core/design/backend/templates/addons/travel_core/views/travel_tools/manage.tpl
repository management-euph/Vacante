{** Travel Core - Tools & Cron
    Travel Core's own job, the one cron key every travel add-on shares, and one
    summary row per provider add-on — supplied by that add-on, so this page
    never hard-codes another add-on's jobs. **}

{capture name="mainbox"}

<div id="travel-cron-tools" class="travel-cron"
     data-key="{$cron_key|escape:html}"
     data-mask="{$cron_key_mask|escape:html}"
     data-txt-copied="{__("travel_core.tools_copied")|escape:html}"
     data-txt-copy-failed="{__("travel_core.tools_copy_failed")|escape:html}"
     data-txt-reveal="{__("travel_core.tools_reveal")|escape:html}"
     data-txt-hide="{__("travel_core.tools_hide")|escape:html}">

    <p class="travel-cron-subtitle">{__("travel_core.tools_cron_subtitle")}</p>

    {* ── At a glance ── *}
    <div class="travel-cron-tiles">
        <div class="travel-cron-tile">
            <div class="travel-cron-tile__label">{__("travel_core.tools_cron_key_title")}</div>
            {if $cron_key}
                <div class="travel-cron-tile__value">{__("travel_core.tools_tile_key_active", ["[n]" => $cron_used_by|count])}</div>
                <div class="travel-cron-tile__note">
                    {if $cron_key_changed_at}{__("travel_core.tools_key_changed_at", ["[date]" => $cron_key_changed_at])}{else}{__("travel_core.tools_key_changed_unknown")}{/if}
                </div>
            {else}
                <div class="travel-cron-tile__value travel-cron-text--bad">{__("travel_core.tools_tile_key_missing")}</div>
            {/if}
        </div>
        <div class="travel-cron-tile">
            <div class="travel-cron-tile__label">{__("travel_core.tools_tile_addons_title")}</div>
            <div class="travel-cron-tile__value">{__("travel_core.tools_tile_addons_value", ["[addons]" => $provider_rows|count, "[jobs]" => $cron_jobs_total])}</div>
            <div class="travel-cron-tile__note">{__("travel_core.tools_tile_addons_core")}</div>
        </div>
        <div class="travel-cron-tile{if $cron_attention} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("travel_core.tools_tile_attention_title")}</div>
            {if $cron_attention}
                <div class="travel-cron-tile__value">{__("travel_core.tools_tile_attention_some", ["[n]" => $cron_attention|count])}</div>
                <div class="travel-cron-tile__note">
                    {foreach from=$cron_attention item=att name=att}{$att.label|escape:html}{if !$smarty.foreach.att.last}, {/if}{/foreach}
                    · <a href="#travel-addon-cron">{__("travel_core.tools_tile_attention_link")}</a>
                </div>
            {else}
                <div class="travel-cron-tile__value">{__("travel_core.tools_tile_attention_none")}</div>
            {/if}
        </div>
    </div>

    {* ── The shared key ── *}
    <section class="travel-cron-card" aria-labelledby="travel-cron-key-title">
        <div class="travel-cron-card__head">
            <div>
                <h3 id="travel-cron-key-title">{__("travel_core.tools_cron_key_title")}</h3>
                <p class="muted">{__("travel_core.tools_cron_key_desc")}</p>
            </div>
            {if $cron_key}
            <form method="post" action="{"travel_tools.generate_cron_key"|fn_url}">
                <input type="hidden" name="security_hash" value="{$security_hash}" />
                <button type="submit" class="btn travel-cron-btn--danger cm-confirm"
                        data-ca-confirm-text="{__("travel_core.tools_cron_key_rotate_confirm")|escape:html}">
                    {__("travel_core.tools_cron_key_rotate")}
                </button>
            </form>
            {/if}
        </div>

        {if $cron_key}
        <div class="travel-cron-keyrow">
            <label for="travel-cron-key">{__("travel_core.tools_key_label")}</label>
            {* Shown masked; tools-cron.js reveals it on request. *}
            <input id="travel-cron-key" type="text" readonly="readonly" value="{$cron_key_mask|escape:html}" autocomplete="off" spellcheck="false" />
            <button type="button" class="btn" id="travel-cron-key-reveal" aria-pressed="false">{__("travel_core.tools_reveal")}</button>
            <button type="button" class="btn btn-primary" id="travel-cron-key-copy">{__("travel_core.tools_copy_key")}</button>
        </div>

        {if !$cron_key_is_shared}
        <div class="alert alert-warning">{__("travel_core.tools_cron_key_legacy")}</div>
        {/if}

        <div class="travel-cron-usedby">
            <span class="muted">{__("travel_core.tools_cron_key_used_by")}</span>
            {foreach from=$cron_used_by item=who}<span class="travel-cron-chip">{$who|escape:html}</span>{/foreach}
            <span class="muted">· {__("travel_core.tools_cron_key_stops")}</span>
        </div>

        {if $cron_recopy_open}
        <div class="travel-cron-recopy" role="region" aria-labelledby="travel-cron-recopy-title">
            <div class="travel-cron-recopy__head">
                <h4 id="travel-cron-recopy-title">{__("travel_core.tools_recopy_title")}</h4>
                <span>{__("travel_core.tools_recopy_progress", ["[done]" => $cron_recopy_done, "[total]" => $cron_recopy|count])}</span>
            </div>
            <ul class="travel-cron-recopy__list">
                {foreach from=$cron_recopy item=entry}
                <li class="travel-cron-recopy__item{if $entry.done} is-done{/if}">
                    <span class="travel-cron-recopy__name">
                        {if $entry.done}<i class="icon-ok" aria-hidden="true"></i>{/if}
                        {$entry.label|escape:html}
                    </span>
                    {if $entry.proved}
                        <span class="travel-cron-text--ok">{__("travel_core.tools_recopy_proved")}</span>
                    {elseif $entry.done}
                        <span class="muted">{__("travel_core.tools_recopy_ticked")}</span>
                    {else}
                        <a class="btn" href="{$entry.url|escape:html}">{__("travel_core.tools_recopy_open")}</a>
                        <form method="post" action="{"travel_tools.cron_recopy_done"|fn_url}">
                            <input type="hidden" name="security_hash" value="{$security_hash}" />
                            <input type="hidden" name="page" value="{$entry.page|escape:html}" />
                            <button type="submit" class="btn">{__("travel_core.tools_recopy_mark_done")}</button>
                        </form>
                    {/if}
                </li>
                {/foreach}
            </ul>
            <div class="travel-cron-recopy__foot">
                <p>{__("travel_core.tools_recopy_note")}</p>
                <form method="post" action="{"travel_tools.cron_recopy_dismiss"|fn_url}">
                    <input type="hidden" name="security_hash" value="{$security_hash}" />
                    <button type="submit" class="btn btn-link">{__("travel_core.tools_recopy_dismiss")}</button>
                </form>
            </div>
        </div>
        {/if}
        {/if}

        {if !$cron_key}
        <div class="alert alert-warning">
            <strong>{__("warning")}</strong>:
            {__("travel_core.tools_no_cron_key")}
            <a href="{"addons.update?addon=travel_core"|fn_url}">{__("travel_core.tools_addon_settings")}</a>
        </div>
        <form method="post" action="{"travel_tools.generate_cron_key"|fn_url}">
            <input type="hidden" name="security_hash" value="{$security_hash}" />
            <button type="submit" class="btn btn-primary">{__("travel_core.tools_cron_key_generate")}</button>
        </form>
        {/if}
    </section>

    {* ── Travel Core's own job ── *}
    <section id="travel-core-jobs" class="travel-cron-card travel-cron-card--flush" aria-labelledby="travel-core-jobs-title">
        <div class="travel-cron-card__head travel-cron-card__head--pad">
            <div>
                <h3 id="travel-core-jobs-title">{__("travel_core.tools_core_jobs_title")}</h3>
                <p class="muted">{__("travel_core.tools_core_jobs_desc")}</p>
            </div>
            {if $cron_key}
            <div class="btn-group" role="group" aria-label="{__("travel_core.tools_format_label")|escape:html}">
                <button type="button" class="btn" data-cron-format="url" aria-pressed="true">{__("travel_core.tools_format_url")}</button>
                <button type="button" class="btn" data-cron-format="cli" aria-pressed="false">{__("travel_core.tools_format_cli")}</button>
            </div>
            {/if}
        </div>

        <table class="table table-middle travel-cron-table">
            <thead>
                <tr>
                    <th>{__("travel_core.tools_col_job")}</th>
                    <th>{__("travel_core.tools_col_command")}</th>
                    <th>{__("travel_core.tools_col_schedule")}</th>
                    <th>{__("travel_core.tools_col_last_run")}</th>
                    <th class="right">{__("travel_core.tools_col_actions")}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>{__("travel_core.cron_exchange_rates")}</strong>
                        <div class="muted">{__("travel_core.cron_exchange_rates_desc")}</div>
                    </td>
                    <td class="travel-cron-table__cmd">
                        {if $cron_key}
                            {* The key is masked here; data-* carry the real
                               commands for Copy. No link ever opens this URL:
                               it would put the key in browser history and in a
                               Referer header. *}
                            <code id="travel-cron-cmd-{$core_job.mode}" class="travel-cron-cmd"
                                  data-cmd-url="{$core_job.cmd_url|escape:html}"
                                  data-cmd-cli="{$core_job.cmd_cli|escape:html}">{$core_job.cmd_masked|escape:html}</code>
                        {else}
                            <span class="muted">{__("travel_core.tools_command_needs_key")}</span>
                        {/if}
                    </td>
                    <td>
                        {__("travel_core.tools_schedule_daily_at")}
                        <div><code>{$core_job.cpanel}</code></div>
                    </td>
                    <td>
                        {if $core_job.record.started|default:0 > $core_job.record.finished|default:0}
                            <span class="travel-cron-pill travel-cron-pill--none">{__("travel_core.tools_run_running", ["[date]" => $core_job.started_fmt])}</span>
                        {elseif $core_job.finished_fmt && $core_job.record.ok}
                            <span class="travel-cron-pill travel-cron-pill--ok"><i class="icon-ok" aria-hidden="true"></i> {__("travel_core.tools_run_ok", ["[date]" => $core_job.finished_fmt])}</span>
                        {elseif $core_job.finished_fmt}
                            <span class="travel-cron-pill travel-cron-pill--bad"><i class="icon-remove" aria-hidden="true"></i> {__("travel_core.tools_run_failed", ["[date]" => $core_job.finished_fmt])}</span>
                            {if $core_job.record.error}<div class="travel-cron-hint travel-cron-hint--bad">{$core_job.record.error|escape:html}</div>{/if}
                        {else}
                            <span class="muted">{__("travel_core.tools_run_never")}</span>
                        {/if}
                        {if $core_health.state == "refused"}
                            <div class="travel-cron-hint travel-cron-hint--bad">{__("travel_core.tools_health_refused_hint")}</div>
                        {/if}
                    </td>
                    <td class="right travel-cron-table__actions">
                        {if $cron_key}
                        <button type="button" class="btn travel-cron-copy" data-copy-target="travel-cron-cmd-{$core_job.mode}">{__("travel_core.tools_copy")}</button>
                        {/if}
                        <form method="post" action="{"travel_tools.`$core_job.run_action`"|fn_url}">
                            <input type="hidden" name="security_hash" value="{$security_hash}" />
                            <button type="submit" class="btn btn-primary">{__("travel_core.tools_run_now")}</button>
                        </form>
                    </td>
                </tr>
            </tbody>
        </table>
        {if $cron_key}
        <p class="travel-cron-foot"><i class="icon-lock" aria-hidden="true"></i> {__("travel_core.tools_key_hidden_note")}</p>
        {/if}
    </section>

    {* ── One row per provider, as each provider declared it ── *}
    <section id="travel-addon-cron" class="travel-cron-card travel-cron-card--flush" aria-labelledby="travel-addon-cron-title">
        <div class="travel-cron-card__head travel-cron-card__head--pad">
            <div>
                <h3 id="travel-addon-cron-title">{__("travel_core.tools_addons_title")}</h3>
                <p class="muted">{__("travel_core.tools_addons_desc")}</p>
            </div>
        </div>

        {if $provider_rows}
        <table class="table table-middle travel-cron-table">
            <thead>
                <tr>
                    <th>{__("travel_core.tools_col_addon")}</th>
                    <th>{__("travel_core.tools_col_jobs")}</th>
                    <th>{__("travel_core.tools_col_health")}</th>
                    <th>{__("travel_core.tools_col_last_activity")}</th>
                    <th class="right">{__("travel_core.tools_col_commands")}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$provider_rows item=row}
                <tr>
                    <td><strong>{$row.label|escape:html}</strong></td>
                    <td>{__("travel_core.tools_jobs_count", ["[n]" => $row.jobs])}</td>
                    <td>{include file="addons/travel_core/views/travel_tools/components/health.tpl" health=$row.health lists=$row.lists}</td>
                    <td>
                        {if $row.last_at_fmt}
                            {__("travel_core.tools_last_activity", ["[mode]" => $row.health.last.mode|escape:html, "[date]" => $row.last_at_fmt])}
                        {else}
                            <span class="muted">—</span>
                        {/if}
                    </td>
                    <td class="right">
                        <a class="btn" href="{$row.url|escape:html}">{__("travel_core.tools_open_cron", ["[addon]" => $row.label|escape:html])} <i class="icon-arrow-right" aria-hidden="true"></i></a>
                    </td>
                </tr>
                {/foreach}
            </tbody>
        </table>
        <p class="travel-cron-foot">{__("travel_core.tools_addons_note")}</p>
        {else}
        <p class="travel-cron-foot">{__("travel_core.tools_addons_none")}</p>
        {/if}
    </section>

    {* ── Maintenance and help ── *}
    <div class="travel-cron-split">
        <section class="travel-cron-card" aria-labelledby="travel-cron-maint-title">
            <h3 id="travel-cron-maint-title">{__("travel_core.tools_maintenance_title")}</h3>
            <p class="muted">{__("travel_core.tools_maintenance_desc")}</p>
            <div class="travel-cron-task">
                <div>
                    <strong>{__("travel_core.link_booking_orders")}</strong>
                    <div class="muted">{__("travel_core.link_booking_orders_desc")}</div>
                </div>
                <form method="post" action="{"travel_tools.link_booking_orders"|fn_url}">
                    <input type="hidden" name="security_hash" value="{$security_hash}" />
                    <button type="submit" class="btn btn-primary">{__("travel_core.tools_run_now")}</button>
                </form>
            </div>
        </section>

        <section class="travel-cron-card travel-cron-card--info" aria-labelledby="travel-cron-help-title">
            <h3 id="travel-cron-help-title">{__("travel_core.tools_help_title")}</h3>
            <ol>
                <li>{__("travel_core.tools_help_step1")}</li>
                <li>{__("travel_core.tools_help_step2")}</li>
                <li>{__("travel_core.tools_help_step3")}</li>
            </ol>
        </section>
    </div>

</div>

{* Inside the capture: admin AJAX navigation only runs scripts that are. *}
{script src="js/addons/travel_core/tools-cron.js"}

{/capture}

{capture name="buttons"}{/capture}

{include file="common/mainbox.tpl"
    title=__("travel_core.tools_page_title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
