{extends file="$base_template_path/error.tpl"}

{block name="message_header"}Доступ к дистанционному электронному голосованию запрещен{/block}

{block name="message_add_text" }

    <link rel="stylesheet" type="text/css" href="{$CFG_CSS_HOST}/common/css/new/forms/mgik/mgd2019.css?{$smarty.now|date_format:'%Y-%m-%dT%H'}" />
    <div class="error-user-message">
        {$errorUserMessage}
    </div>

    <div class="form-result-back-button d-inline-block pb-0 w-100" style="text-align: right">
        <span class="right">
            <a href="{$elk_host}/my/#profile" class="btn btn-primary btn-lg">Перейти в личный кабинет</a>
        </span>
    </div>

{/block}