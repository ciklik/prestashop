{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
<section id="{$moduleName}-displayAdminOrderMainBottom">
  <div class="card mt-2">
    <div class="card-header">
      <h3 class="card-header-title">
        <img src="{$moduleLogoSrc}" alt="{$moduleDisplayName}" width="20" height="20">
        {l s='CIKLIK Refunds' mod='ciklik'}<br>
      </h3>
    </div>
    <div class="card-body">
      <div class="row">
        <div class="col">
          <p>{l s='This will be applied in your Ciklik dashboard automatically.' mod='ciklik'}</p>
        </div>
      </div>
      <div class="alert alert-success ciklik-success"  style="display:none" data-alert="success"></div>
      <div class="alert alert-danger ciklik-danger" style="display:none" data-alert="danger"></div>
        <div class="alert alert-info ciklik-info" {if ! $refund.refunded}style="display:none"{/if}>
          {l s='Refunded:' mod='ciklik'}<span id="refunded">{$refund.refunded}</span>
        </div>
      {if $refund.available}
        <form id="ciklik-refund" method="POST" action="{$actionUrl|escape:'htmlall':'UTF-8'}" class="defaultForm form-horizontal form-ciklik disabled">
          <input type="hidden" name="orderId" required value="{$order.id|escape:'htmlall':'UTF-8'}"/>
          <input type="hidden" name="ajax_token" value="{$ajaxToken|escape:'htmlall':'UTF-8'}"/>
          <div class="form-group row">
            <label class='control-label text-right col-lg-4'>
              <span class="text-danger">*</span> {l s='Refund type' mod='ciklik'}
            </label>
            <div class="col-sm">
              <div class="radio t">
                <label>
                  <input type="radio" autocomplete="off" class="refundType form-check-input" id="total" name="refundType" value="total" checked="checked"/>
                  {l s='Full' mod='ciklik'}
                </label>
              </div>
              <div class="radio t">
                <label>
                  <input type="radio" autocomplete="off" class="refundType form-check-input" name="refundType" value="partial"/>
                  {l s='Partial' mod='ciklik'}
                </label>
              </div>
            </div>
          </div>
          <div class="form-group row" id="amountDisplay" style="display: none">
            <label class='control-label text-right col-lg-4' for="amount">
              <span class="text-danger">*</span><span id="maxRefundable">{$refund.max}<span>
            </label>
            <div class="col-sm">
              <div class="input-group">
                <input
                        type="text"
                        name="amount"
                        autocomplete="off"
                        id="amount"
                        placeholder="{l s='Amount to refund...' mod='ciklik'}"
                />
                <div class="input-group-append">
                  <div class="input-group-text">{$order.currencySymbol|escape:'htmlall':'UTF-8'}</div>
                </div>
              </div>
            </div>
          </div>
          <div class="text-right">
            <button type="submit" class="button btn btn-primary button-medium pull-right">{l s='Proceed the refund' mod='ciklik'}</button>
          </div>
        </form>
      {/if}
    </div>
  </div>
</section>

<script type="text/javascript">
  $(function () {
    var $form = $('form#ciklik-refund');

    $('input[type=radio][name=refundType]').change(function () {
      if (this.value === 'partial') {
        $('#amountDisplay').show();
        $($form.find('[name=amount]')).prop('required', true);
      } else {
        $('#amountDisplay').hide();
        $($form.find('[name=amount]')).prop('required', false);
      }
    });


    $form.submit(function (e) {
      if (e) {
        e.preventDefault();
        $($form.find('[type=submit]')).attr("disabled", true);
        $('.ciklik-danger').html('').hide();
        $('.ciklik-success').html('').hide();

        $.ajax({
          type: 'POST',
          url: $form.attr('action'),
          dataType: 'json',
          data: {
            ajax: true,
            action: 'Refund',
            orderId: $form.find('[name=orderId]').val(),
            refundType: $form.find('[name=refundType]:checked').val(),
            amount: $form.find('[name=amount]').val(),
            ajax_token: $form.find('[name=ajax_token]').val()
          }
        })
                .done(function (data) {
                  $('.ciklik-success').html(data.message).show();
                  $('#refunded').html(data.refund.refunded);
                  $('#maxRefundable').html(data.refund.max);
                  $('.ciklik-info').show();
                  if (! data.refund.available) {
                    $form.hide()
                  }
                })
                .fail(function (data) {
                  var jsondata = JSON.parse(data.responseText);
                  $('.ciklik-danger').html(jsondata.message).show();
                })
                .always(function(){
                  $($form.find('[type=submit]')).attr('disabled', false);
                  $($form.find('input[id=amount]')).val('');
                });

        return false;
      }
    });
  });
</script>
