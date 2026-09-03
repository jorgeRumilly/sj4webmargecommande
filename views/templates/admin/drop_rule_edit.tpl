{*
* SJ4WEB.FR - Marge Commande - edit a supplier dropshipping rule
*}
{assign var=r value=$sj4wm_rule}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-truck"></i>
        {l s='Rule for supplier' d='Modules.Sj4webmargecommande.Admin'} :
        #{$sj4wm_supplier.id|intval} — {$sj4wm_supplier.name|escape:'html':'UTF-8'}
    </div>

    <form method="post" action="{$sj4wm_form_action|escape:'html':'UTF-8'}" class="form-horizontal" id="sj4wm-rule-form">
        <input type="hidden" name="id_supplier" value="{$sj4wm_supplier.id|intval}">
        <input type="hidden" name="id_rule" value="{if $r}{$r.id_rule|intval}{else}0{/if}">

        <div class="form-group">
            <label class="control-label col-lg-3">{l s='Active' d='Modules.Sj4webmargecommande.Admin'}</label>
            <div class="col-lg-3">
                <input type="checkbox" name="active" value="1" {if !$r || $r.active}checked{/if}>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">{l s='Rule type' d='Modules.Sj4webmargecommande.Admin'}</label>
            <div class="col-lg-4">
                <select name="rule_type" id="sj4wm-rule-type" class="form-control">
                    {foreach $sj4wm_types as $t}
                        <option value="{$t}" {if $r && $r.rule_type == $t}selected{/if}>{$t}</option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">{l s='Drop fee already covers shipping' d='Modules.Sj4webmargecommande.Admin'}</label>
            <div class="col-lg-6" style="padding-top:6px;">
                <input type="checkbox" name="drop_covers_shipping" value="1" {if $r && $r.drop_covers_shipping}checked{/if}>
                <span class="help-block" style="display:inline;margin-left:6px;">
                    {l s='ON (e.g. Castex): the rule amount is the all-in cost, no shipping added on top. OFF: shipping is billed separately and entered per order.' d='Modules.Sj4webmargecommande.Admin'}
                </span>
            </div>
        </div>

        {* percent *}
        <div class="form-group sj4wm-type sj4wm-type-percent">
            <label class="control-label col-lg-3">{l s='Percent' d='Modules.Sj4webmargecommande.Admin'}</label>
            <div class="col-lg-2">
                <div class="input-group"><input type="text" class="form-control" name="percent" value="{if $r && $r.percent !== null}{$r.percent}{/if}"><span class="input-group-addon">%</span></div>
            </div>
        </div>
        <div class="form-group sj4wm-type sj4wm-type-percent">
            <label class="control-label col-lg-3">{l s='Percent base' d='Modules.Sj4webmargecommande.Admin'}</label>
            <div class="col-lg-4">
                <select name="percent_base" class="form-control">
                    <option value="purchase" {if !$r || $r.percent_base != 'sale'}selected{/if}>{l s='Purchase price (excl. shipping)' d='Modules.Sj4webmargecommande.Admin'}</option>
                    <option value="sale" {if $r && $r.percent_base == 'sale'}selected{/if}>{l s='Our selling price HT (before coupons, excl. shipping)' d='Modules.Sj4webmargecommande.Admin'}</option>
                </select>
                <p class="help-block">{l s='Computed on non-refunded quantities only.' d='Modules.Sj4webmargecommande.Admin'}</p>
            </div>
        </div>

        {* fixed *}
        <div class="form-group sj4wm-type sj4wm-type-fixed">
            <label class="control-label col-lg-3">{l s='Fixed amount per order (HT)' d='Modules.Sj4webmargecommande.Admin'}</label>
            <div class="col-lg-2">
                <div class="input-group"><input type="text" class="form-control" name="fixed_amount" value="{if $r && $r.fixed_amount !== null}{$r.fixed_amount}{/if}"><span class="input-group-addon">€</span></div>
            </div>
        </div>

        {* per_quantity *}
        <div class="sj4wm-type sj4wm-type-per_quantity">
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Quantity steps' d='Modules.Sj4webmargecommande.Admin'}</label>
                <div class="col-lg-6">
                    <p class="help-block">{l s='First step whose "max qty" is >= the supplier quantity in the order wins.' d='Modules.Sj4webmargecommande.Admin'}</p>
                    <table class="table" id="sj4wm-steps">
                        <thead><tr><th>{l s='Max qty' d='Modules.Sj4webmargecommande.Admin'}</th><th>{l s='Amount HT' d='Modules.Sj4webmargecommande.Admin'}</th></tr></thead>
                        <tbody>
                        {if $r && $r.steps}
                            {foreach $r.steps as $st}
                                <tr>
                                    <td><input type="text" class="form-control" name="step_max_qty[]" value="{$st.max_quantity|intval}"></td>
                                    <td><input type="text" class="form-control" name="step_amount[]" value="{$st.amount}"></td>
                                </tr>
                            {/foreach}
                        {/if}
                        <tr>
                            <td><input type="text" class="form-control" name="step_max_qty[]" value=""></td>
                            <td><input type="text" class="form-control" name="step_amount[]" value=""></td>
                        </tr>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-default btn-sm" id="sj4wm-add-step"><i class="icon-plus"></i> {l s='Add step' d='Modules.Sj4webmargecommande.Admin'}</button>
                </div>
            </div>
        </div>

        {* bucket_flat *}
        <div class="sj4wm-type sj4wm-type-bucket_flat">
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Type buckets' d='Modules.Sj4webmargecommande.Admin'}</label>
                <div class="col-lg-8">
                    <p class="help-block">{l s='One flat amount is added per bucket that has at least one matching product in the order (quantity ignored). A product matches a bucket when one of its categories is a selected category or a descendant of it.' d='Modules.Sj4webmargecommande.Admin'}</p>
                    <div id="sj4wm-buckets">
                    {assign var=bidx value=0}
                    {if $r && $r.buckets}
                        {foreach $r.buckets as $b}
                            {include file="./drop_rule_bucket_row.tpl" b=$b cats=$sj4wm_categories idx=$bidx}
                            {assign var=bidx value=$bidx+1}
                        {/foreach}
                    {/if}
                        {include file="./drop_rule_bucket_row.tpl" b=null cats=$sj4wm_categories idx=$bidx}
                    </div>
                    <button type="button" class="btn btn-default btn-sm" id="sj4wm-add-bucket"><i class="icon-plus"></i> {l s='Add bucket' d='Modules.Sj4webmargecommande.Admin'}</button>
                </div>
            </div>
        </div>

        <div class="panel-footer">
            <a href="{$sj4wm_list_url|escape:'html':'UTF-8'}" class="btn btn-default"><i class="icon-arrow-left"></i> {l s='Back' d='Modules.Sj4webmargecommande.Admin'}</a>
            <button type="submit" name="submitDropRule" value="1" class="btn btn-primary pull-right"><i class="icon-save"></i> {l s='Save' d='Modules.Sj4webmargecommande.Admin'}</button>
            {if $r}
                <button type="submit" name="deleteDropRule" value="1" class="btn btn-danger"
                        onclick="return confirm('{l s='Delete this rule?' d='Modules.Sj4webmargecommande.Admin' js=1}');">
                    <input type="hidden" name="id_rule" value="{$r.id_rule|intval}">
                    <i class="icon-trash"></i> {l s='Delete' d='Modules.Sj4webmargecommande.Admin'}
                </button>
            {/if}
        </div>
    </form>
</div>

<script>
{literal}
(function () {
    var typeSel = document.getElementById('sj4wm-rule-type');
    function refresh() {
        var t = typeSel.value;
        document.querySelectorAll('.sj4wm-type').forEach(function (el) {
            el.style.display = el.classList.contains('sj4wm-type-' + t) ? '' : 'none';
        });
    }
    typeSel.addEventListener('change', refresh);
    refresh();

    document.getElementById('sj4wm-add-step').addEventListener('click', function () {
        var tb = document.querySelector('#sj4wm-steps tbody');
        var tr = tb.rows[tb.rows.length - 1].cloneNode(true);
        tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        tb.appendChild(tr);
    });

    document.getElementById('sj4wm-add-bucket').addEventListener('click', function () {
        var wrap = document.getElementById('sj4wm-buckets');
        var last = wrap.lastElementChild;
        var newIdx = parseInt(last.getAttribute('data-idx'), 10) + 1;
        var row = last.cloneNode(true);
        row.setAttribute('data-idx', newIdx);
        row.querySelectorAll('[name]').forEach(function (el) {
            el.setAttribute('name', el.getAttribute('name').replace(/\[\d+\]/, '[' + newIdx + ']'));
        });
        row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        row.querySelectorAll('select option').forEach(function (o) { o.selected = false; });
        wrap.appendChild(row);
    });
})();
{/literal}
</script>
