{** block-description:pages_in_blocks **}

{if $items}
<div id="pages_content_{$block.block_id}">
{foreach from=$items item="page" name="block_pages"}
{if $items|sizeof != 1 || $page.page_type == $smarty.const.PAGE_TYPE_LINK || $page.show_in_popup == 'Y'}
<p>
	{if $page.page_type == $smarty.const.PAGE_TYPE_LINK}
		{assign var="href" value=$page.link|fn_url}
	{else}
		{assign var="href" value="pages.view?page_id=`$page.page_id`"|fn_url}
	{/if}
	{capture name="attributes"}
		{if $page.show_in_popup == 'Y'} 
			class="cm-dialog-opener{if $page.page_type == "F"} cm-dialog-auto-width{/if} strong" 
			id="opener_page_tl_{$page.page_id}" 
			data-ca-target-id="page_tl_{$page.page_id}"
			rel="nofollow"
		{else}
			class="strong"
			{if $page.new_window}
				target="_blank"
			{/if}
		{/if}
	{/capture}
	<a href="{$href}" {$smarty.capture.attributes nofilter}><strong>{$page.page nofilter}</strong></a>
</p>
{/if}
{if $page.show_in_popup != 'Y' && $page.page_type != $smarty.const.PAGE_TYPE_LINK}
{$display_button_block = false} {* hide social buttons *}
<p>
	<div class="wysiwyg-content">
		{hook name="pages:page_content"}
		{$page.description nofilter}
		{/hook}
	</div>
</p>
{hook name="pages:page_extra"}
{/hook}
{elseif $page.show_in_popup == 'Y'}
	 <div id="page_tl_{$page.page_id}" class="hidden" title="{$page.page}"></div>
{/if}
{if !$smarty.foreach.block_pages.last}<p><hr/></p>{/if} 
{/foreach}
</div>
{/if}

<script type="text/javascript">
//<![CDATA[
(function(_, $) {
	$(document).ready(function(){
		$('.ty-captcha__input', '#pages_content_{$block.block_id}').css('margin-bottom', '10px').css('width', '100%');		
		$('.buttons-container', '#pages_content_{$block.block_id}').removeClass('buttons-container').addClass('ty-right');
	});
}(Tygh, Tygh.$));
//]]>
</script>