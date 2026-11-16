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
<style>
    .dms-grid-2 { display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); }
    .dms-facts { display: grid; grid-template-columns: max-content 1fr; gap: .5rem 1.25rem; margin: 0; font-size: .875rem; }
    .dms-facts dt { color: #4A5F59; }
    .dms-facts dd { margin: 0; }
    .dms-strong { font-weight: 700; font-size: 1rem; }
    .dms-alert { color: #A12626; }
    .dms-scroll { overflow-x: auto; }
    .dms-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .dms-table th { text-align: left; font-weight: 600; color: #4A5F59; padding: .5rem .75rem; border-bottom: 1px solid #DCE7E2; white-space: nowrap; }
    .dms-table td { padding: .625rem .75rem; border-bottom: 1px solid #E6EEEA; vertical-align: top; }
    .dms-table .dms-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .dms-table .dms-total td { font-weight: 700; border-bottom: 0; }
    .dms-struck td { color: #6B7F79; text-decoration: line-through; }
    .dms-link { color: #17735D; font-weight: 600; text-decoration: underline; }
</style>
<style>
    .dms-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.25rem; }
    .dms-textarea { width: 100%; border: 1px solid #C9D8D2; border-radius: .5rem; padding: .5rem .75rem; font: inherit; }
    .dms-textarea:focus { outline: 2px solid #17735D; outline-offset: 1px; }
</style>
<style>
    .dms-filters { display: flex; flex-wrap: wrap; gap: 1rem; }
    .dms-filters label { display: grid; gap: .375rem; font-size: .875rem; font-weight: 500; min-width: 11rem; }
</style>
