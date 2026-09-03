{*
* SJ4WEB.FR - Marge Commande - dropshipping cost rules, supplier list
*}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-truck"></i> {l s='Dropshipping cost rules per supplier' d='Modules.Sj4webmargecommande.Admin'}
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>{l s='Supplier' d='Modules.Sj4webmargecommande.Admin'}</th>
                <th>{l s='Rule' d='Modules.Sj4webmargecommande.Admin'}</th>
                <th>{l s='Active' d='Modules.Sj4webmargecommande.Admin'}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        {foreach $sj4wm_suppliers as $s}
            <tr>
                <td>#{$s.id_supplier|intval} — {$s.name|escape:'html':'UTF-8'}</td>
                <td>{$s.summary|escape:'html':'UTF-8'}</td>
                <td>{if $s.active}<span class="badge badge-success">{l s='yes' d='Modules.Sj4webmargecommande.Admin'}</span>{else}<span class="badge">{l s='no' d='Modules.Sj4webmargecommande.Admin'}</span>{/if}</td>
                <td class="text-right">
                    <a class="btn btn-default btn-sm" href="{$s.edit_url|escape:'html':'UTF-8'}">
                        <i class="icon-edit"></i> {l s='Edit' d='Modules.Sj4webmargecommande.Admin'}
                    </a>
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
</div>
