{*
* SJ4WEB.FR - Marge Commande - admin order side widget
*}
<div id="sj4web-marge" class="card mt-2">
    <div class="card-header">
        {l s='Margin' d='Modules.Sj4webmargecommande.Admin'}
        {if $m.net_margin < 0}<span class="badge badge-danger pull-right">{l s='loss' d='Modules.Sj4webmargecommande.Admin'}</span>{/if}
        {if $m.has_store_credit}<span class="badge badge-info pull-right" style="margin-right:4px;">{l s='store credit' d='Modules.Sj4webmargecommande.Admin'}</span>{/if}
    </div>
    <div class="card-body">

        <p class="mb-1">
            <strong>{l s='Payment method' d='Modules.Sj4webmargecommande.Admin'} :</strong>
            {$m.payment_method|escape:'html':'UTF-8'}
        </p>

        {if $m.gift_card_sales_ht > 0}
            <p class="mb-1">
                <span class="badge badge-warning">{l s='Gift card sale (excluded from margin)' d='Modules.Sj4webmargecommande.Admin'}</span>
                <small class="text-muted">{l s='This order sells a gift card: its amount is a liability, not turnover.' d='Modules.Sj4webmargecommande.Admin'} (- {$m.gift_card_sales_ht|string_format:"%.2f"} €)</small>
            </p>
        {/if}

        <table class="table mb-1">
            <tbody>
                <tr>
                    <td>{l s='Net revenue (HT, shipping incl., after vouchers/refunds)' d='Modules.Sj4webmargecommande.Admin'}</td>
                    <td class="text-right">{$m.revenue_ht|string_format:"%.2f"} €</td>
                </tr>
                {if $m.store_credit_ht > 0}
                <tr>
                    <td><small class="text-muted">{l s='incl. gift card / voucher (payment means)' d='Modules.Sj4webmargecommande.Admin'}</small></td>
                    <td class="text-right"><small class="text-muted">+ {$m.store_credit_ht|string_format:"%.2f"} €</small></td>
                </tr>
                {/if}
                <tr>
                    <td style="color:{$c.cost};">
                        {l s='Purchase cost (HT)' d='Modules.Sj4webmargecommande.Admin'}
                        {if $m.cost_incomplete}
                            <span class="badge badge-warning" title="{l s='Some order lines have no supplier purchase price' d='Modules.Sj4webmargecommande.Admin'}">
                                {l s='incomplete' d='Modules.Sj4webmargecommande.Admin'}
                            </span>
                        {/if}
                    </td>
                    <td class="text-right" style="color:{$c.cost};">- {$m.cost_price_ht|string_format:"%.2f"} €</td>
                </tr>
                <tr>
                    <td>
                        {l s='Supplier cost (drop + shipping, HT)' d='Modules.Sj4webmargecommande.Admin'}
                        {if $m.port_pending}<span class="badge badge-warning" title="{l s='Real shipping not entered for at least one supplier' d='Modules.Sj4webmargecommande.Admin'}">{l s='shipping est.' d='Modules.Sj4webmargecommande.Admin'}</span>{/if}
                        {if $m.drop_by_supplier}
                            <br>
                            {foreach $m.drop_by_supplier as $s}
                                <small class="text-muted">
                                    {$s.label|escape:'html':'UTF-8'} —
                                    {if $s.covers_shipping}
                                        {$s.total|string_format:"%.2f"} € {if $s.port_real !== null}({l s='real, all-in' d='Modules.Sj4webmargecommande.Admin'}){else}({l s='est., all-in' d='Modules.Sj4webmargecommande.Admin'}){/if}
                                    {else}
                                        {l s='drop' d='Modules.Sj4webmargecommande.Admin'} {$s.drop_estimate|string_format:"%.2f"} € ({l s='est.' d='Modules.Sj4webmargecommande.Admin'})
                                        + {l s='shipping' d='Modules.Sj4webmargecommande.Admin'}
                                        {if $s.port_real !== null}{$s.port_real|string_format:"%.2f"} €{else}<span style="color:#e67e00;">— ⚠</span>{/if}
                                        = {$s.total|string_format:"%.2f"} €
                                    {/if}
                                </small><br>
                            {/foreach}
                        {/if}
                    </td>
                    <td class="text-right">- {$m.drop_cost_ht|string_format:"%.2f"} €</td>
                </tr>
                <tr>
                    <td>
                        {l s='Payment commission (HT)' d='Modules.Sj4webmargecommande.Admin'}
                        {if $m.commission_percent !== null}
                            <small style="color:{$c.commission};font-weight:bold;">({$m.commission_percent|string_format:"%.2f"} %)</small>
                        {/if}
                    </td>
                    <td class="text-right">- {$m.commission_ht|string_format:"%.2f"} €</td>
                </tr>
            </tbody>
        </table>

        <p class="mb-0 text-center" style="font-size:110%;">
            <strong>{l s='Net margin' d='Modules.Sj4webmargecommande.Admin'} :</strong>
            <span class="badge" style="font-size:100%;background:{$c.net_margin};color:#fff;">{$m.net_margin|string_format:"%.2f"} €</span>
        </p>
        <p class="mb-0 mt-1 text-center">
            {if $m.markup_rate !== null}<span style="color:{$c.markup_rate};">{l s='markup' d='Modules.Sj4webmargecommande.Admin'} {$m.markup_rate|string_format:"%.1f"} %</span>{/if}
            {if $m.margin_rate !== null}<span class="text-muted"> · </span><span style="color:{$c.margin_rate};">{l s='margin rate' d='Modules.Sj4webmargecommande.Admin'} {$m.margin_rate|string_format:"%.1f"} %</span>{/if}
        </p>

        <p class="mb-0 mt-2"><small class="text-muted">
            {l s='Shipping charged' d='Modules.Sj4webmargecommande.Admin'} {$m.shipping_charged_ht|string_format:"%.2f"} € ·
            {l s='shipping paid' d='Modules.Sj4webmargecommande.Admin'} {$m.port_paid_ht|string_format:"%.2f"} € ·
            Δ <span style="color:{if $m.shipping_delta_ht < 0}#cc0000{else}#00994d{/if};">{$m.shipping_delta_ht|string_format:"%.2f"} €</span>
            {if $m.port_pending}<span style="color:#e67e00;"> ({l s='incomplete' d='Modules.Sj4webmargecommande.Admin'})</span>{/if}
        </small></p>

        {if $m.refund_products_ht > 0 || $m.refund_shipping_ht > 0}
            <p class="mb-0 mt-1"><small class="text-muted">
                {l s='Refunds deducted' d='Modules.Sj4webmargecommande.Admin'} :
                {l s='products' d='Modules.Sj4webmargecommande.Admin'} {$m.refund_products_ht|string_format:"%.2f"} € ·
                {l s='shipping' d='Modules.Sj4webmargecommande.Admin'} {$m.refund_shipping_ht|string_format:"%.2f"} €
            </small></p>
        {/if}

        {if $sj4wm_suppliers}
        <hr>
        <form id="sj4wm-actual-form" data-url="{$sj4wm_ajax_url|escape:'html':'UTF-8'}" data-id-order="{$sj4wm_id_order|intval}">
            <p class="mb-1"><strong>{l s='Real shipping paid, per supplier (HT)' d='Modules.Sj4webmargecommande.Admin'}</strong></p>
            {foreach $sj4wm_suppliers as $s}
                <div class="form-group row" style="margin-bottom:6px;">
                    <label class="col-lg-5 col-form-label" style="padding-top:6px;">
                        {$s.label|escape:'html':'UTF-8'}
                        {if $s.covers_shipping}<br><small class="text-muted">{l s='drop covers shipping — enter the real all-in' d='Modules.Sj4webmargecommande.Admin'}</small>
                        {else}<br><small class="text-muted">{l s='drop est.' d='Modules.Sj4webmargecommande.Admin'} {$s.drop_estimate|string_format:"%.2f"} €</small>{/if}
                    </label>
                    <div class="col-lg-4">
                        <input type="hidden" name="sup_id[]" value="{$s.id_supplier|intval}">
                        <div class="input-group">
                            <input type="text" class="form-control" name="sup_amount[]" value="{if $s.port_real !== null}{$s.port_real|string_format:"%.2f"}{/if}" placeholder="{l s='real' d='Modules.Sj4webmargecommande.Admin'}">
                            <span class="input-group-addon">€</span>
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <input type="text" class="form-control" name="sup_note[]" value="{$s.note|escape:'html':'UTF-8'}" placeholder="{l s='note' d='Modules.Sj4webmargecommande.Admin'}">
                    </div>
                </div>
            {/foreach}
            <button type="submit" class="btn btn-primary btn-sm"><i class="icon-save"></i> {l s='Save & recompute' d='Modules.Sj4webmargecommande.Admin'}</button>
            <span id="sj4wm-actual-msg" style="margin-left:8px;"></span>
        </form>
        {literal}
        <script>
        (function () {
            var f = document.getElementById('sj4wm-actual-form');
            if (!f) return;
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                var msg = document.getElementById('sj4wm-actual-msg');
                msg.textContent = '…';
                var data = new FormData(f);
                data.append('ajax', '1');
                data.append('action', 'SaveOrderDrop');
                data.append('id_order', f.getAttribute('data-id-order'));
                fetch(f.getAttribute('data-url') + '&ajax=1&action=SaveOrderDrop', { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.success) { location.reload(); }
                        else { msg.textContent = (j && j.error) ? j.error : 'Erreur'; msg.style.color = '#cc0000'; }
                    })
                    .catch(function () { msg.textContent = 'Erreur réseau'; msg.style.color = '#cc0000'; });
            });
        })();
        </script>
        {/literal}
        {/if}
    </div>
</div>
