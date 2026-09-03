{*
* SJ4WEB.FR - Marge Commande - configuration panel
*}
<div class="panel">
    <div class="panel-heading"><i class="icon-dashboard"></i> {l s='Margin cache & tools' d='Modules.Sj4webmargecommande.Admin'}</div>

    <div class="row">
        <div class="col-lg-6">
            <table class="table">
                <tbody>
                    <tr><th>{l s='Valid orders' d='Modules.Sj4webmargecommande.Admin'}</th><td>{$stats.orders_valid|intval}</td></tr>
                    <tr><th>{l s='In cache' d='Modules.Sj4webmargecommande.Admin'}</th><td>{$stats.cached|intval}</td></tr>
                    <tr><th>{l s='Missing' d='Modules.Sj4webmargecommande.Admin'}</th><td>{$stats.missing|intval}</td></tr>
                    <tr><th>{l s='Incomplete purchase cost' d='Modules.Sj4webmargecommande.Admin'}</th><td>{$stats.cost_incomplete|intval}</td></tr>
                </tbody>
            </table>

            <form method="post" action="{$form_action|escape:'html':'UTF-8'}" style="display:inline-block;">
                <button type="submit" name="sj4web_margin_recompute" value="1" class="btn btn-primary">
                    <i class="icon-refresh"></i> {l s='Compute missing' d='Modules.Sj4webmargecommande.Admin'}
                </button>
            </form>
            <form method="post" action="{$form_action|escape:'html':'UTF-8'}" style="display:inline-block;"
                  onsubmit="return confirm('{l s='Rebuild the whole margin cache?' d='Modules.Sj4webmargecommande.Admin' js=1}');">
                <input type="hidden" name="scope" value="all">
                <button type="submit" name="sj4web_margin_recompute" value="1" class="btn btn-default">
                    <i class="icon-repeat"></i> {l s='Rebuild all' d='Modules.Sj4webmargecommande.Admin'}
                </button>
            </form>
        </div>

        <div class="col-lg-6">
            <p>
                <a href="{$rules_url|escape:'html':'UTF-8'}" class="btn btn-default"><i class="icon-cogs"></i> {l s='Dropshipping cost rules' d='Modules.Sj4webmargecommande.Admin'}</a>
                <a href="{$list_url|escape:'html':'UTF-8'}" class="btn btn-default"><i class="icon-list"></i> {l s='Order margins list' d='Modules.Sj4webmargecommande.Admin'}</a>
            </p>
            <h4>{l s='Cron' d='Modules.Sj4webmargecommande.Admin'}</h4>
            <p class="help-block">{l s='Schedule once a day:' d='Modules.Sj4webmargecommande.Admin'}</p>
            <pre style="white-space:pre-wrap;word-break:break-all;">{$cron_url|escape:'html':'UTF-8'}</pre>
            <a href="{$regen_url|escape:'html':'UTF-8'}" class="btn btn-default btn-sm"
               onclick="return confirm('{l s='Regenerate the cron token?' d='Modules.Sj4webmargecommande.Admin' js=1}');">
                <i class="icon-refresh"></i> {l s='Regenerate token' d='Modules.Sj4webmargecommande.Admin'}
            </a>
        </div>
    </div>
</div>
