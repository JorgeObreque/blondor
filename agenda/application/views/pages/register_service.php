<?php extend('layouts/backend_layout'); ?>

<?php section('content'); ?>

<div class="container-fluid backend-page" id="providers-page">
    <div class="row" id="providers">
        <div id="filter-providers" class="filter-records column col-12 col-md-5">
            <form class="mb-4">
                <div class="input-group">
                    <input type="text" class="key form-control" aria-label="keyword">

                    <button class="filter btn btn-outline-secondary" type="submit"
                            data-tippy-content="<?= lang('filter') ?>">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>

            <h4 class="text-black-50 mb-3 fw-light">
                <?= lang('providers') ?>
            </h4>

            <?php slot('after_page_title'); ?>

            <div class="results">
                <!-- JS -->
            </div>
            
        </div>
        <div class="col-md-5">
            <div class="input-group">
                <!-- <input type="selecy" class="key form-control" aria-label="keyword"> -->
                <select class="form-control" id="service-list"  aria-label="keyword"></select>

                <button class="filter btn btn-outline-secondary" id="add-service"
                        data-tippy-content="<?= lang('add') ?>">
                    <i class="fas fa-add"></i>
                </button>
            </div>
            <table id="services-table" class="table table-bordered mt-3">
            <thead>
                <tr>
                <th>Nombre</th>
                <th>Precio</th>
                <th>Comisión</th>
                <th>Pago a realizar</th>
                <th>Acciones</th>
                </tr>
            </thead>
            <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>

<script src="<?= asset_url('assets/vendor/jquery-jeditable/jquery.jeditable.min.js') ?>"></script>
<script src="<?= asset_url('assets/js/utils/date.js') ?>"></script>
<script src="<?= asset_url('assets/js/utils/message.js') ?>"></script>
<script src="<?= asset_url('assets/js/utils/string.js') ?>"></script>
<script src="<?= asset_url('assets/js/utils/ui.js') ?>"></script>
<script src="<?= asset_url('assets/js/utils/url.js') ?>"></script>
<script src="<?= asset_url('assets/js/utils/validation.js') ?>"></script>
<script src="<?= asset_url('assets/js/utils/working_plan.js') ?>"></script>
<script src="<?= asset_url('assets/js/http/account_http_client.js') ?>"></script>
<script src="<?= asset_url('assets/js/http/providers_http_client.js') ?>"></script>
<script src="<?= asset_url('assets/js/http/register_service_http_client.js') ?>"></script>
<script src="<?= asset_url('assets/js/pages/register_service.js?v='.Date('his')) ?>"></script>

<?php end_section('scripts'); ?>



