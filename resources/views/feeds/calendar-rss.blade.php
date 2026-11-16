<rss version="2.0">
    <channel>
        <title>{{ $title }}</title>
        <link>{{ $link }}</link>
        <description>{{ $description }}</description>
        <language>{{ $language }}</language>
        <ttl>{{ $ttl }}</ttl>
@foreach ($items as $item)
        <item>
            <title>{{ $item['title'] }}</title>
            <link>{{ $item['link'] }}</link>
            <guid isPermaLink="false">{{ $item['guid'] }}</guid>
            <pubDate>{{ $item['published'] }}</pubDate>
            <description><![CDATA[@if ($item['cover'] !== null)<p><img src="{{ $item['cover'] }}" alt="" loading="lazy"></p>@endif @foreach ($item['facts'] as $fact)<p>{{ $fact }}</p>@endforeach]]></description>
        </item>
@endforeach
    </channel>
</rss>
