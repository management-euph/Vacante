<div class="control-group">
    <div class="well">
        <h4>{__("novoton_holidays.quick_actions")}</h4>
        <p>
            <a href="admin.php?dispatch=novoton_holidays.manage" class="btn btn-primary">
                {__("novoton_holidays.open_dashboard")}
            </a>
        </p>
        <p class="muted">
            {__("novoton_holidays.cron_info_description")}
        </p>

        {* The commands used to be listed here as "php …/index.php?dispatch=…"
           lines, which do not run (php cannot execute a URL) and carried a
           YOUR_KEY placeholder. They live once, correct, on the dashboard:
           CLI and URL forms, the real key on Copy, and when each job last ran. *}
        <h4>{__("novoton_holidays.cron_commands")}</h4>
        <p class="muted">{__("novoton_holidays.dash_settings_jobs_moved")}</p>
        <p>
            <a href="{"novoton_holidays.manage"|fn_url}#novoton-cron-jobs" class="btn">
                {__("novoton_holidays.dash_open_jobs")}
            </a>
        </p>
    </div>
</div>
