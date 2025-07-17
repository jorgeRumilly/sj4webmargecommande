<div id="fraismarge" class="fraismarge card">
    <div class="card-header pb-0" style="padding-bottom: 0!important;">{l s='Frais et marge' d='Modules.Sj4webmargecommande.Sj4webmargecommande'}</div>
    <div class="card-body">
        <div class="info-block">
            <div class="row">
                <div id="fraismarge_mtn" class="info-block-col col-xxl-6">
                    <p class="mb-1"><strong>{l s='PV HT' d='Modules.Sj4webmargecommande.Sj4webmargecommande'}
                            :</strong> {$orderTotalHT} €</p>
                    <p class="mb-0"><strong>{l s='PA HT' d='Modules.Sj4webmargecommande.Sj4webmargecommande'}
                            :</strong> {$orderCostPrice} €</p>
                </div>
                <div id="fraismarge_mtn" class="info-block-col col-xxl-6">
                    <p class="mb-1">
                        <strong>{l s='Frais Drop' d='Modules.Sj4webmargecommande.Sj4webmargecommande'}
                            :</strong> {$dropshippingFees} €</p>
                    <p class="mb-0"><strong>{l s='Frais Comm' d='Modules.Sj4webmargecommande.Sj4webmargecommande'}
                            :</strong> <span class="badge rounded bg-primary font-size-100">{$paymentFees} €</span></p>
                </div>
            </div>
        </div>
        <div class="info-block mt-1">
            <div class="row">
                <div class="col-xxl-12 text-center mt-1">
                    <p class="mb-0 mt-0"><strong>{l s='Marge' d='Modules.Sj4webmargecommande.Sj4webmargecommande'} :</strong>
                        <span class="badge rounded badge-dark font-size-100">{$netMargin} €</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
