{**
 * Module settings: Modules → Module Manager → Supertext Translation → Configure.
 *}
<form method="post" action="{$st.formUrl|escape:'html':'UTF-8'}" class="supertext-settings form-horizontal" autocomplete="off">
  <div class="panel" id="supertext-connection">
    <div class="panel-heading"><i class="icon-key"></i> {l s='Supertext connection' d='Modules.Supertext.Admin'}</div>

    <div class="form-wrapper">
      <div class="form-group">
        <label class="control-label col-lg-3" for="supertext-api-key">{l s='Supertext API key' d='Modules.Supertext.Admin'}</label>
        <div class="col-lg-6">
          {if $st.keyFromEnv}
            <p class="form-control-static">{l s='Set by the SUPERTEXT_API_KEY environment variable on the server.' d='Modules.Supertext.Admin'}</p>
          {else}
            <input type="password" id="supertext-api-key" name="api_key" value="" class="form-control"
                   placeholder="{if $st.hasKey}{l s='Saved' d='Modules.Supertext.Admin'} ({$st.keyHint|escape:'html':'UTF-8'}){else}Supertext-Auth-Key …{/if}">
            {if $st.hasKey}
              <div class="checkbox"><label><input type="checkbox" name="remove_api_key" value="1"> {l s='Remove the saved key' d='Modules.Supertext.Admin'}</label></div>
            {/if}
          {/if}
          <p class="help-block">
            {l s='Paste the key as Supertext shows it, with or without "Supertext-Auth-Key". Leave empty to keep the saved key.' d='Modules.Supertext.Admin'}<br>
            {l s='No Supertext account yet?' d='Modules.Supertext.Admin'}
            <a href="{$st.signupUrl|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='Create one at supertext.com.' d='Modules.Supertext.Admin'}</a>
            {l s='Generate your API key at' d='Modules.Supertext.Admin'}
            <a href="{$st.apiKeyUrl|escape:'html':'UTF-8'}" target="_blank" rel="noopener">supertext.com → Integrations → API</a>
            {l s='(requires the Admin role).' d='Modules.Supertext.Admin'}
          </p>
        </div>
      </div>

      <div class="form-group">
        <label class="control-label col-lg-3" for="supertext-environment">{l s='API environment' d='Modules.Supertext.Admin'}</label>
        <div class="col-lg-4">
          <select id="supertext-environment" name="environment" class="form-control">
            <option value="live"{if $st.environment == 'live'} selected{/if}>{l s='Live' d='Modules.Supertext.Admin'} (api.supertext.com)</option>
            <option value="staging"{if $st.environment == 'staging'} selected{/if}>{l s='Staging' d='Modules.Supertext.Admin'}</option>
            <option value="testing"{if $st.environment == 'testing'} selected{/if}>{l s='Testing' d='Modules.Supertext.Admin'}</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="control-label col-lg-3" for="supertext-endpoint">{l s='Custom API base URL' d='Modules.Supertext.Admin'}</label>
        <div class="col-lg-6">
          <input type="text" id="supertext-endpoint" name="endpoint" value="{$st.endpoint|escape:'html':'UTF-8'}" class="form-control" placeholder="https://api.supertext.com/v1/">
          <p class="help-block">
            {l s='Optional. Overrides the environment, e.g. for a test server.' d='Modules.Supertext.Admin'}
            {if $st.endpointFromEnv}<br><strong class="st-endpoint-env">{l s='The SUPERTEXT_API_ENDPOINT environment variable is set and is used instead.' d='Modules.Supertext.Admin'}</strong>{/if}
          </p>
        </div>
      </div>

      <div class="form-group">
        <label class="control-label col-lg-3" for="supertext-timeout">{l s='Timeout (seconds)' d='Modules.Supertext.Admin'}</label>
        <div class="col-lg-2">
          <input type="number" id="supertext-timeout" name="timeout" min="30" max="1800" value="{$st.timeout|intval}" class="form-control">
        </div>
        <div class="col-lg-4"><p class="help-block">{l s='Maximum wait for one translation.' d='Modules.Supertext.Admin'}</p></div>
      </div>
    </div>

    <div class="panel-footer">
      <button type="submit" name="submitSupertextSettings" value="1" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save' d='Modules.Supertext.Admin'}</button>
      <button type="submit" name="submitSupertextTest" value="1" class="btn btn-default"><i class="process-icon-refresh"></i> {l s='Test connection' d='Modules.Supertext.Admin'}</button>
    </div>
  </div>

  <div class="panel" id="supertext-languages">
    <div class="panel-heading"><i class="icon-flag"></i> {l s='Languages' d='Modules.Supertext.Admin'}</div>
    <p>
      {l s='Supertext translates into the languages of your shop.' d='Modules.Supertext.Admin'}
      {l s='By default the locale of the language (e.g. de-CH) is sent; enter a different code to override it, e.g. fr-CH for Swiss French.' d='Modules.Supertext.Admin'}
      <a href="{$st.languagesUrl|escape:'html':'UTF-8'}">{l s='Manage languages' d='Modules.Supertext.Admin'}</a>
    </p>
    <table class="table">
      <thead>
        <tr>
          <th>{l s='Language' d='Modules.Supertext.Admin'}</th>
          <th>{l s='Locale' d='Modules.Supertext.Admin'}</th>
          <th>{l s='Supertext language code' d='Modules.Supertext.Admin'}</th>
          <th>{l s='Tone' d='Modules.Supertext.Admin'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $st.languages as $language}
          <tr>
            <td>
              {$language.name|escape:'html':'UTF-8'}
              {if $language.isDefault}<span class="badge">{l s='Default' d='Modules.Supertext.Admin'}</span>{/if}
              {if !$language.active}<span class="label label-default">{l s='Inactive' d='Modules.Supertext.Admin'}</span>{/if}
            </td>
            <td><code>{$language.locale|escape:'html':'UTF-8'}</code></td>
            <td><input type="text" name="languages[{$language.id|intval}][code]" value="{$language.code|escape:'html':'UTF-8'}" class="form-control" placeholder="{$language.locale|escape:'html':'UTF-8'}" style="max-width:10em"></td>
            <td>
              <select name="languages[{$language.id|intval}][tone]" class="form-control" style="max-width:16em">
                <option value="default"{if $language.tone == 'default'} selected{/if}>{l s='Supertext default' d='Modules.Supertext.Admin'}</option>
                <option value="more"{if $language.tone == 'more'} selected{/if}>{l s='Formal (Sie, vous)' d='Modules.Supertext.Admin'}</option>
                <option value="less"{if $language.tone == 'less'} selected{/if}>{l s='Informal (du, tu)' d='Modules.Supertext.Admin'}</option>
              </select>
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
    <div class="panel-footer">
      <button type="submit" name="submitSupertextSettings" value="1" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save' d='Modules.Supertext.Admin'}</button>
    </div>
  </div>

  <div class="panel" id="supertext-about">
    <div class="panel-heading"><i class="icon-info"></i> {l s='How to translate' d='Modules.Supertext.Admin'}</div>
    <p>
      {l s='In Catalog → Products, Catalog → Categories or Design → Pages, tick the items and choose Translate with Supertext in the bulk actions, or use the action in the menu of a row. On a product page, the Modules tab has the same button.' d='Modules.Supertext.Admin'}
      <a href="{$st.docsUrl|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='User guide' d='Modules.Supertext.Admin'}</a>
    </p>
    <p class="text-muted">
      {l s='Version' d='Modules.Supertext.Admin'}
      {if $st.releaseUrl}<a href="{$st.releaseUrl|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{$st.version|escape:'html':'UTF-8'}</a>{else}{$st.version|escape:'html':'UTF-8'}{/if}
    </p>
  </div>
</form>
