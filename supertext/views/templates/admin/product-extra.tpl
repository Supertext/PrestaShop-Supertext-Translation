{**
 * Product page, Modules tab: opens the Supertext translate page for this product.
 *}
<div class="supertext-product-extra" style="max-width:44rem;padding-top:1rem">
  <h3>{l s='Supertext Translation' d='Modules.Supertext.Admin'}</h3>
  <p>{l s='Translate this product into your other shop languages: name, descriptions, SEO fields and delivery texts.' d='Modules.Supertext.Admin'}</p>
  {if !$st.hasApiKey}
    <div class="alert alert-warning">{l s='Supertext is not set up yet: an administrator needs to enter the API key in the module settings.' d='Modules.Supertext.Admin'}</div>
  {/if}
  <p class="supertext-product-extra-note">{l s='Save your changes first: the translation uses the saved product.' d='Modules.Supertext.Admin'}</p>
  <a class="btn btn-primary" href="{$st.url|escape:'html':'UTF-8'}">
    <i class="material-icons">translate</i> {l s='Translate with Supertext' d='Modules.Supertext.Admin'}
  </a>
</div>
