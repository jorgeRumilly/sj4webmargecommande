{*
* SJ4WEB.FR - Marge Commande - filtres numériques avancés au-dessus de la liste
*}
{extends file="helpers/list/list_header.tpl"}

{block name="preTable"}
    <form method="post" class="form-horizontal" id="sj4wm-advanced-filters">
        <div class="panel">
            <div class="panel-heading">Filtres avancés</div>
            <div class="form-wrapper">
                <div class="form-group">
                    <label class="control-label col-lg-3">Marge nette (€) entre</label>
                    <div class="col-lg-2"><input class="form-control" type="text" name="margin_min" value="{$custom_filters.margin_min|escape:'html':'UTF-8'}" placeholder="min"></div>
                    <div class="col-lg-2"><input class="form-control" type="text" name="margin_max" value="{$custom_filters.margin_max|escape:'html':'UTF-8'}" placeholder="max"></div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">Taux de marge (%) entre</label>
                    <div class="col-lg-2"><input class="form-control" type="text" name="margin_rate_min" value="{$custom_filters.margin_rate_min|escape:'html':'UTF-8'}" placeholder="min"></div>
                    <div class="col-lg-2"><input class="form-control" type="text" name="margin_rate_max" value="{$custom_filters.margin_rate_max|escape:'html':'UTF-8'}" placeholder="max"></div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">Taux de marque (%) entre</label>
                    <div class="col-lg-2"><input class="form-control" type="text" name="markup_rate_min" value="{$custom_filters.markup_rate_min|escape:'html':'UTF-8'}" placeholder="min"></div>
                    <div class="col-lg-2"><input class="form-control" type="text" name="markup_rate_max" value="{$custom_filters.markup_rate_max|escape:'html':'UTF-8'}" placeholder="max"></div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">Commission (%) entre</label>
                    <div class="col-lg-2"><input class="form-control" type="text" name="commission_percent_min" value="{$custom_filters.commission_percent_min|escape:'html':'UTF-8'}" placeholder="min"></div>
                    <div class="col-lg-2"><input class="form-control" type="text" name="commission_percent_max" value="{$custom_filters.commission_percent_max|escape:'html':'UTF-8'}" placeholder="max"></div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">Avoir / carte cadeau</label>
                    <div class="col-lg-4">
                        <select class="form-control" name="has_store_credit">
                            <option value="">Toutes les commandes</option>
                            <option value="1"{if $custom_filters.has_store_credit == '1'} selected{/if}>Avec avoir / carte cadeau</option>
                            <option value="0"{if $custom_filters.has_store_credit == '0'} selected{/if}>Sans</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="panel-footer text-right">
                <button type="submit" name="sj4web_margin_reset_ranges" value="1" class="btn btn-default"><i class="icon-remove"></i> Réinitialiser</button>
                <button type="submit" name="sj4web_margin_apply_ranges" value="1" class="btn btn-default"><i class="icon-search"></i> Appliquer</button>
            </div>
        </div>
    </form>

    <div class="pull-right" style="margin:10px 0;">{$button_exel nofilter}</div>
{/block}
