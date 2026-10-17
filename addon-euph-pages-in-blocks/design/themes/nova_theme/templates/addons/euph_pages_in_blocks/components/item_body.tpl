{*
    The body of a page shown in a Page content block. Params: item, pib.

    Full content: the page's text through the pages:page_content hook, as
    on the page itself, so the Form builder and Polls add-ons draw the form
    or the poll in its place; forms and polls also get pages:page_extra.
    Excerpts: the start of the text and a link to the page.
*}
{if $pib.excerpts}
    {if $item.excerpt}
        <p class="euph-pib__excerpt">{$item.excerpt}</p>
    {/if}
    <p class="euph-pib__more">
        {include file="addons/euph_pages_in_blocks/components/item_link.tpl" item=$item class="euph-pib__more-link" text=__("euph_pages_in_blocks.read_more") label=__("euph_pages_in_blocks.read_more_about", ["[title]" => $item.title])}
    </p>
{else}
    {* The hooks' templates read the page as $page; the share buttons stay
       out of the block. *}
    {$page = $item.page}
    {$display_button_block = false}
    <div class="ty-wysiwyg-content euph-pib__content">
        {hook name="pages:page_content"}
            {$page.description nofilter}
        {/hook}
    </div>
    {if $item.extra_hook}
        {hook name="pages:page_extra"}{/hook}
    {/if}
{/if}
