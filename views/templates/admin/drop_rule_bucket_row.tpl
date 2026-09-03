{*
* SJ4WEB.FR - Marge Commande - one bucket row (bucket_flat rule)
* params: b (bucket|null), cats (category options), idx (row index)
*}
<div class="sj4wm-bucket panel panel-default" data-idx="{$idx}" style="padding:10px;margin-bottom:10px;">
    <div class="row">
        <div class="col-lg-4">
            <label>{l s='Label' d='Modules.Sj4webmargecommande.Admin'}</label>
            <input type="text" class="form-control" name="bucket_label[{$idx}]" value="{if $b}{$b.label|escape:'html':'UTF-8'}{/if}">
        </div>
        <div class="col-lg-2">
            <label>{l s='Amount HT' d='Modules.Sj4webmargecommande.Admin'}</label>
            <div class="input-group"><input type="text" class="form-control" name="bucket_amount[{$idx}]" value="{if $b}{$b.amount}{/if}"><span class="input-group-addon">€</span></div>
        </div>
        <div class="col-lg-6">
            <label>{l s='Categories' d='Modules.Sj4webmargecommande.Admin'}</label>
            <select multiple class="form-control" name="bucket_categories[{$idx}][]" size="6">
                {foreach $cats as $c}
                    <option value="{$c.id_category|intval}"
                        {if $b && in_array($c.id_category, $b.categories)}selected{/if}>{$c.label|escape:'html':'UTF-8'}</option>
                {/foreach}
            </select>
        </div>
    </div>
</div>
