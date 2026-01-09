{**
 * Partial de pagination réutilisable
 * 
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
{if isset($pagination) && $pagination.total_pages > 1}
    <div class="panel-footer">
        <div class="row">
            <div class="col-lg-6">
                {l s='Page' mod='ciklik'} {$pagination.current_page|escape:'html':'UTF-8'} {l s='sur' mod='ciklik'} {$pagination.total_pages|escape:'html':'UTF-8'}
                {if isset($pagination.from) && isset($pagination.to)}
                    ({l s='Résultats' mod='ciklik'} {$pagination.from|escape:'html':'UTF-8'} - {$pagination.to|escape:'html':'UTF-8'} {l s='sur' mod='ciklik'} {$pagination.total|escape:'html':'UTF-8'})
                {/if}
            </div>
            <div class="col-lg-6 text-right">
                <div class="btn-group" role="group">
                    {if isset($pagination_links.first) && $pagination_links.first}
                        <a href="{$pagination_links.first|escape:'html':'UTF-8'}" class="btn btn-default btn-sm">
                            <i class="icon-angle-double-left"></i> {l s='Première' mod='ciklik'}
                        </a>
                    {/if}
                    {if isset($pagination_links.prev) && $pagination_links.prev}
                        <a href="{$pagination_links.prev|escape:'html':'UTF-8'}" class="btn btn-default btn-sm">
                            <i class="icon-angle-left"></i> {l s='Précédent' mod='ciklik'}
                        </a>
                    {/if}
                    
                    {if isset($pagination_links.pages) && $pagination_links.pages|@count > 0}
                        {foreach $pagination_links.pages as $page}
                            {if $page.current}
                                <span class="btn btn-default btn-sm active">{$page.number|escape:'html':'UTF-8'}</span>
                            {else}
                                <a href="{$page.url|escape:'html':'UTF-8'}" class="btn btn-default btn-sm">{$page.number|escape:'html':'UTF-8'}</a>
                            {/if}
                        {/foreach}
                    {/if}
                    
                    {if isset($pagination_links.next) && $pagination_links.next}
                        <a href="{$pagination_links.next|escape:'html':'UTF-8'}" class="btn btn-default btn-sm">
                            {l s='Suivant' mod='ciklik'} <i class="icon-angle-right"></i>
                        </a>
                    {/if}
                    {if isset($pagination_links.last) && $pagination_links.last}
                        <a href="{$pagination_links.last|escape:'html':'UTF-8'}" class="btn btn-default btn-sm">
                            {l s='Dernière' mod='ciklik'} <i class="icon-angle-double-right"></i>
                        </a>
                    {/if}
                </div>
            </div>
        </div>
    </div>
{/if}

