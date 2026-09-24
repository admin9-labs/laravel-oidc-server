<style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; background: #f3f4f6; color: #111827; font: 1rem/1.5 system-ui, sans-serif; }
    main { width: 100%; max-width: 28rem; padding: 1.5rem; background: white; border-radius: .5rem; box-shadow: 0 1px 4px #0002; }
    h1 { margin: 0; font-size: 1.25rem; }
    p, ul { color: #374151; }
    .actions { display: flex; gap: .75rem; margin-top: 1.5rem; }
    .actions > * { flex: 1; min-width: 0; }
    button, .cancel { display: block; width: 100%; padding: .6rem 1rem; border: 0; border-radius: .375rem; font: inherit; text-align: center; cursor: pointer; }
    button { background: #2563eb; color: white; }
    .secondary, .cancel { background: #e5e7eb; color: #111827; text-decoration: none; }
    button:hover { filter: brightness(.9); }
    button:focus-visible, a:focus-visible { outline: 3px solid #111827; outline-offset: 3px; }
    .client { color: #1d4ed8; }
</style>
