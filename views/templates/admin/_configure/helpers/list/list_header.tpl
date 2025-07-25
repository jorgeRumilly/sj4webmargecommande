{*
* 2007-2013 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2013 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}

{extends file="helpers/list/list_header.tpl"}

{block name="preTable"}

    {* Formulaire de filtres personnalisés *}
    <form method="post" class="form-horizontal" id="advanced_filters_form">
        <div class="panel">
            <div class="panel-heading">Filtres avancés</div>
            <div class="form-wrapper">
                <div class="form-group">
                    <label class="control-label col-lg-2">Total TTC entre</label>
                    <div class="col-lg-2">
                        <input class="form-control" type="text" name="filter_min_total_ttc" value="{$custom_filters.filter_min_total_ttc}" />
                    </div>
                    <div class="col-lg-2">
                        <input class="form-control" type="text" name="filter_max_total_ttc" value="{$custom_filters.filter_max_total_ttc}" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-2">% Marge entre</label>
                    <div class="col-lg-2">
                        <input class="form-control" type="text" name="filter_min_margin_rate" value="{$custom_filters.filter_min_margin_rate}" />
                    </div>
                    <div class="col-lg-2">
                        <input class="form-control" type="text" name="filter_max_margin_rate" value="{$custom_filters.filter_max_margin_rate}" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-2">% Commission entre</label>
                    <div class="col-lg-2">
                        <input class="form-control" type="text" name="filter_min_commission_percent" value="{$custom_filters.filter_min_commission_percent}" />
                    </div>
                    <div class="col-lg-2">
                        <input class="form-control" type="text" name="filter_max_commission_percent" value="{$custom_filters.filter_max_commission_percent}" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-2">% Frais totaux entre</label>
                    <div class="col-lg-2">
                        <input class="form-control" type="text" name="filter_min_fees_rate" value="{$custom_filters.filter_min_fees_rate}" />
                    </div>
                    <div class="col-lg-2">
                        <input class="form-control" type="text" name="filter_max_fees_rate" value="{$custom_filters.filter_max_fees_rate}" />
                    </div>
                </div>
            </div>
            <div class="panel-footer text-right">
                <button type="submit" name="submitFiltersj4webmargecommande_fees" class="btn btn-default">
                    <i class="icon-search"></i> Appliquer
                </button>
            </div>
        </div>
    </form>

    {* Bouton export existant *}
    <div class="pull-right" style="margin-top: 10px;">
        {$button_exel}
    </div>

{/block}
