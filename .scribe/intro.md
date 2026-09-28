# Introduction

High-traffic news feed API: users, follows, posts, likes and a hybrid fan-out feed.

<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>

    Every error uses the same envelope: `{ "message": "...", "code": "POST_NOT_FOUND", "errors": {} }`.

    The feed uses cursor pagination: pass `meta.next_cursor` back as `cursor` until `meta.has_more` is false.

