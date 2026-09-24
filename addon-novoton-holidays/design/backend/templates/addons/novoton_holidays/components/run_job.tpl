{* Run one cron job from the dashboard: novoton_holidays.run_job.

   A POST form, never a link to the public cron URL — that URL carries the
   shared cron key, which a link leaks into browser history and Referer
   headers, and a GET "Reset" can be fired by any page the admin opens. CS-Cart
   checks the security_hash. The job's log opens in a new window:
   dashboard.js opens it and posts into it (CS-Cart's admin scripts took the
   submit over and ignored target="_blank", which stays as the no-JS fallback).

   Params: job (mode), label, action (status|force_full|reset, optional),
           class (button classes), confirm (question to ask first, optional),
           menuitem (true inside a ⋯ menu). *}
<form action="{""|fn_url}" method="post" target="_blank" class="novoton-run-form"{if $confirm} data-novoton-confirm="{$confirm|escape:html}"{/if}>
    <input type="hidden" name="dispatch" value="novoton_holidays.run_job" />
    <input type="hidden" name="security_hash" value="{$security_hash}" />
    <input type="hidden" name="job" value="{$job|escape:html}" />
    {if $action}<input type="hidden" name="job_action" value="{$action|escape:html}" />{/if}
    <button type="submit" class="{$class|default:"btn"}"{if $menuitem} role="menuitem"{/if}>{$label}</button>
</form>
