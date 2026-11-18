<!doctype html>
<html lang="{{ $locale }}">
<body>
<p>{{ __('app.discussion_email_intro', ['order' => $orderNumber], $locale) }}</p>
<p><a href="{{ $discussionUrl }}">{{ __('app.discussion_email_open', [], $locale) }}</a></p>
<p>{{ __('app.discussion_email_login_hint', [], $locale) }}</p>
</body>
</html>
