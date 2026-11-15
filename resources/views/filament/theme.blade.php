{{--
    Panel theme additions: the dark green sidebar and a few layout helpers
    for our own pages. Button and link colours come from the panel's primary
    palette in AdminPanelProvider.
--}}
<style>
    .fi-body { background: #F3F7F5; }

    .fi-main-sidebar, .fi-sidebar { background: #14403A; }
    .fi-sidebar .fi-sidebar-group-label { color: #B9D3CB; }
    .fi-sidebar .fi-sidebar-group-btn svg { color: #B9D3CB; }
    .fi-sidebar .fi-sidebar-item-label { color: #E6F1ED; }
    .fi-sidebar .fi-sidebar-item-btn svg { color: #B9D3CB; }
    .fi-sidebar .fi-sidebar-item-btn:hover,
    .fi-sidebar .fi-sidebar-item-btn:focus-visible { background: #0E2E29; }
    .fi-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn { background: #FFFFFF; }
    .fi-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn .fi-sidebar-item-label,
    .fi-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn svg { color: #14403A; }

    .dms-stack { display: grid; gap: 1.5rem; }
    .dms-muted { color: #4A5F59; font-size: .875rem; line-height: 1.5; margin: 0; }
    .dms-small { color: #4A5F59; font-size: .8125rem; margin: 0; }
    .dms-module-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); }
    .dms-module { display: grid; gap: .75rem; }
    .dms-module-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
    .dms-module-name { font-size: 1.0625rem; font-weight: 600; margin: 0; }
</style>
