{*
* @author    Metrogeek SAS <support@ciklik.co>
* @copyright Since 2017 Metrogeek SAS
* @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
*}

<div class="panel" id="ciklik-frequencies-by-category">
    <div class="panel-heading">
        <i class="icon-sitemap"></i> {l s='Enable frequencies by category' mod='ciklik'}
    </div>
    <p class="help-block">
        {l s='Adds the chosen frequencies to the active products of a category. Nothing is removed: to remove a frequency, edit the product.' mod='ciklik'}
    </p>

    <form method="post" action="{$ciklik_form_action|escape:'html':'UTF-8'}" class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3" for="ciklik_category">{l s='Category' mod='ciklik'}</label>
            <div class="col-lg-6">
                <select name="ciklik_category" id="ciklik_category">
                    <option value="0">--</option>
                    {foreach from=$ciklik_categories item=category}
                        <option value="{$category.id_category|intval}"{if $category.id_category == $ciklik_selection.id_category} selected="selected"{/if}>
                            {$category.indent|escape:'html':'UTF-8'}{$category.name|escape:'html':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="ciklik_with_children" value="1"{if $ciklik_selection.with_children} checked="checked"{/if}>
                        {l s='Include subcategories' mod='ciklik'}
                    </label>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">{l s='Frequencies to add' mod='ciklik'}</label>
            <div class="col-lg-6">
                {foreach from=$ciklik_frequencies item=frequency}
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="ciklik_category_frequencies[]" value="{$frequency.id_frequency|intval}"{if in_array($frequency.id_frequency, $ciklik_selection.frequencies)} checked="checked"{/if}>
                            {$frequency.name|escape:'html':'UTF-8'}
                        </label>
                    </div>
                {/foreach}
            </div>
        </div>

        {if $ciklik_preview}
            <div class="alert alert-info">
                <p><strong>{l s='Preview of the changes' mod='ciklik'}</strong></p>
                <ul>
                    <li>{l s='Products found:' mod='ciklik'} {$ciklik_preview.found|intval}</li>
                    <li>{l s='Excluded packs:' mod='ciklik'} {$ciklik_preview.excluded.pack|intval}, {l s='virtual products:' mod='ciklik'} {$ciklik_preview.excluded.virtual|intval}, {l s='inactive products:' mod='ciklik'} {$ciklik_preview.excluded.inactive|intval}</li>
                    <li>{l s='Products receiving at least one frequency:' mod='ciklik'} {$ciklik_preview.updated|intval} ({l s='rows added:' mod='ciklik'} {$ciklik_preview.rows|intval})</li>
                    <li>{l s='Products that already have all the chosen frequencies (unchanged):' mod='ciklik'} {$ciklik_preview.unchanged|intval}</li>
                </ul>
                {if $ciklik_preview.names}
                    <p>
                        {foreach from=$ciklik_preview.names item=name name=names}{$name|escape:'html':'UTF-8'}{if !$smarty.foreach.names.last}, {/if}{/foreach}
                        {if $ciklik_preview.more > 0} {l s='and %d more' sprintf=[$ciklik_preview.more] mod='ciklik'}{/if}
                    </p>
                {/if}
            </div>
            <input type="hidden" name="ciklik_fingerprint" value="{$ciklik_preview.fingerprint|escape:'html':'UTF-8'}">
        {/if}

        <div class="panel-footer">
            <button type="submit" name="submitCiklikCategoryPreview" class="btn btn-default">
                <i class="process-icon-preview"></i> {l s='Preview' mod='ciklik'}
            </button>
            {if $ciklik_preview && $ciklik_preview.rows}
                <button type="submit" name="submitCiklikCategoryApply" class="btn btn-primary pull-right"
                        onclick="return confirm('{l s='Add these frequencies to the products shown in the preview?' mod='ciklik' js=1}');">
                    <i class="process-icon-save"></i> {l s='Add these frequencies' mod='ciklik'}
                </button>
            {/if}
        </div>
    </form>
</div>
