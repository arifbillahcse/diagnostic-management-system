{{-- The typed clinic header, on plain paper without a header banner. --}}
@unless ($layout['preprinted'] || $layout['header_image'])
        <table class="letterhead"><tr>
            @if ($clinic['logo'])
                <td class="logo"><img src="{{ $clinic['logo'] }}" alt=""></td>
            @endif
            <td>
                <div class="clinic">{{ $clinic['name'] }}</div>
                @if (! empty($clinic['branch']))<div class="small"><strong>{{ $clinic['branch'] }}</strong></div>@endif
                <div class="small">{{ $clinic['address'] }}</div>
                <div class="small">
                    {{ $clinic['phone'] }}@if ($clinic['phone'] && $clinic['email']) &middot; @endif{{ $clinic['email'] }}
                </div>
                @if ($clinic['header'])<div class="small muted">{!! nl2br(e($clinic['header'])) !!}</div>@endif
            </td>
        </tr></table>
    @endunless
