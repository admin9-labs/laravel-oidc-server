<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>OIDC integration host</title></head>
<body><main><h1>OIDC integration host</h1>
<p>Admin: <strong>{{ Auth::guard('admin')->user()?->email ?? 'signed out' }}</strong></p>
<p>Member: <strong>{{ Auth::guard('member')->user()?->email ?? 'signed out' }}</strong></p>
<a href="/admin/login">Admin sign in</a>
</main></body></html>
