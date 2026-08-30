<?php

declare(strict_types=1);

namespace App\Service\Seed;

use App\Enum\Category;
use App\Enum\EntryType;

/**
 * Kuratierter Basisstock für `index:seed`: echte Suchmaschinen und
 * Website-Menüs. URLs sind verifiziert bzw. entstammen dem autoritativen
 * Extension-Katalog (js/search-engines-catalog.js, js/menu-catalog.js). Für
 * Suchmaschinen ist `url` ein Präfix, an das der (kodierte) Suchbegriff
 * angehängt wird; `plus: true` macht aus Leerzeichen `+`.
 *
 * searchLink-Menüeinträge referenzieren ausschließlich EINGEBAUTE Engines
 * (google, brave, bing, duckduckgo, perplexity, deepl, wikipedia, amazon,
 * ebay) – so bleiben die Menüs überall importierbar (Extension-Bericht §3).
 */
final class SeedCatalog
{
    private const V = '1.0.0';

    /** @return list<SeedEntry> */
    public function entries(): array
    {
        return array_merge(
            $this->generalEngines(),
            $this->germanNewsEngines(),
            $this->intlNewsEngines(),
            $this->referenceEngines(),
            $this->devEngines(),
            $this->mediaEngines(),
            $this->shoppingEngines(),
            $this->socialEngines(),
            $this->imageEngines(),
            $this->toolsEngines(),
            $this->menus(),
        );
    }

    // ---- Builder-Helfer -------------------------------------------------

    /**
     * @param string|array<string,string> $name
     * @param list<string>                $cats
     * @param list<string>                $tags
     * @param array<string,mixed>         $extra
     */
    private function eng(string $id, string|array $name, string $url, array $cats, array $tags = [], array $extra = []): SeedEntry
    {
        $payload = array_merge(['gesturaEngine' => 1, 'id' => $id, 'version' => self::V, 'name' => $name, 'url' => $url], $extra);

        return new SeedEntry($id, EntryType::Engine, self::V, $payload, array_map(static fn (string $c): Category => Category::from($c), $cats), $tags);
    }

    /**
     * @param string|array<string,string> $name
     * @param list<string>                $patterns
     * @param list<array<string,mixed>>   $items
     * @param list<string>                $cats
     * @param list<string>                $tags
     * @param array<string,mixed>         $extra
     */
    private function menu(string $id, string|array $name, array $patterns, array $items, array $cats, array $tags = [], array $extra = []): SeedEntry
    {
        $payload = array_merge(['gesturaMenu' => 1, 'id' => $id, 'version' => self::V, 'name' => $name, 'patterns' => $patterns, 'items' => $items], $extra);

        return new SeedEntry($id, EntryType::Menu, self::V, $payload, array_map(static fn (string $c): Category => Category::from($c), $cats), $tags);
    }

    /** @param string|array<string,string> $label */
    private function link(string $id, string|array $label, string $icon, string $url): array
    {
        return ['id' => $id, 'label' => $label, 'icon' => $icon, 'action' => 'openCustomUrl', 'customUrl' => $url];
    }

    private function srch(string $id, string $engineId): array
    {
        return ['id' => $id, 'action' => 'searchLink', 'engineId' => $engineId];
    }

    private function sep(string $id): array
    {
        return ['id' => $id, 'type' => 'separator'];
    }

    // ---- Suchmaschinen: allgemein & regional ----------------------------

    /** @return list<SeedEntry> */
    private function generalEngines(): array
    {
        $plus = ['plus' => true];

        return [
            $this->eng('com.ecosia.search', 'Ecosia', 'https://www.ecosia.org/search?q=', ['search'], ['web', 'privacy', 'green'], $plus),
            $this->eng('com.startpage.search', 'Startpage', 'https://www.startpage.com/sp/search?query=', ['search'], ['web', 'privacy'], $plus),
            $this->eng('com.qwant.search', 'Qwant', 'https://www.qwant.com/?q=', ['search'], ['web', 'privacy', 'europe'], $plus),
            $this->eng('org.metager.search', 'MetaGer', 'https://metager.org/meta/meta.ger3?eingabe=', ['search'], ['web', 'privacy', 'metasearch'], $plus),
            $this->eng('com.mojeek.search', 'Mojeek', 'https://www.mojeek.com/search?q=', ['search'], ['web', 'privacy'], $plus),
            $this->eng('com.searchenginemarginalia.search', 'Marginalia', 'https://search.marginalia.nu/search?query=', ['search'], ['web', 'indie'], $plus),
            $this->eng('one.kagi.search', 'Kagi', 'https://kagi.com/search?q=', ['search'], ['web', 'premium'], $plus),
            $this->eng('com.you.search', 'You.com', 'https://you.com/search?q=', ['search'], ['web', 'ai'], $plus),
            $this->eng('com.swisscows.search', 'Swisscows', 'https://swisscows.com/en/web?query=', ['search'], ['web', 'privacy'], $plus),
            $this->eng('com.lite.duckduckgo', 'DuckDuckGo Lite', 'https://lite.duckduckgo.com/lite/?q=', ['search'], ['web', 'privacy', 'lite'], $plus),
            $this->eng('com.googlescholar.search', 'Google Scholar', 'https://scholar.google.com/scholar?q=', ['reference'], ['academic', 'papers'], $plus),
            $this->eng('org.searx.search', 'SearXNG', 'https://searx.be/search?q=', ['search'], ['web', 'privacy', 'metasearch'], $plus),
        ];
    }

    /** @return list<SeedEntry> Verifizierte deutsche Nachrichten-Suchen. */
    private function germanNewsEngines(): array
    {
        $plus = ['plus' => true];

        return [
            $this->eng('de.golem.search', ['de' => 'Golem.de'], 'https://suche.golem.de/search.php?q=', ['news', 'dev'], ['tech', 'deutsch', 'it'], $plus),
            $this->eng('de.heise.search', ['de' => 'heise online'], 'https://www.heise.de/suche?q=', ['news', 'dev'], ['tech', 'deutsch', 'it'], $plus),
            $this->eng('de.spiegel.search', ['de' => 'DER SPIEGEL'], 'https://www.spiegel.de/suche/?suchbegriff=', ['news'], ['nachrichten', 'deutsch'], $plus),
            $this->eng('de.tagesschau.search', ['de' => 'tagesschau'], 'https://www.tagesschau.de/suche?searchText=', ['news'], ['nachrichten', 'deutsch'], $plus),
            $this->eng('de.zeit.search', ['de' => 'ZEIT ONLINE'], 'https://www.zeit.de/suche/index?q=', ['news'], ['nachrichten', 'deutsch'], $plus),
            $this->eng('de.mydealz.search', ['de' => 'mydealz'], 'https://www.mydealz.de/search?q=', ['shopping'], ['deals', 'deutsch', 'schnäppchen'], $plus),
            $this->eng('de.sueddeutsche.search', ['de' => 'Süddeutsche Zeitung'], 'https://www.sueddeutsche.de/suche?query=', ['news'], ['nachrichten', 'deutsch'], $plus),
            $this->eng('de.faz.search', ['de' => 'FAZ.NET'], 'https://www.faz.net/suche/?query=', ['news'], ['nachrichten', 'deutsch'], $plus),
        ];
    }

    /** @return list<SeedEntry> */
    private function intlNewsEngines(): array
    {
        $plus = ['plus' => true];

        return [
            $this->eng('com.google.news', 'Google News', 'https://news.google.com/search?q=', ['news'], ['news'], $plus),
            $this->eng('com.theguardian.search', ['en' => 'The Guardian'], 'https://www.theguardian.com/search?q=', ['news'], ['news', 'english'], $plus),
            $this->eng('com.bbc.search', ['en' => 'BBC'], 'https://www.bbc.co.uk/search?q=', ['news'], ['news', 'english'], $plus),
            $this->eng('com.reuters.search', ['en' => 'Reuters'], 'https://www.reuters.com/site-search/?query=', ['news'], ['news', 'english'], $plus),
            $this->eng('com.arstechnica.search', ['en' => 'Ars Technica'], 'https://arstechnica.com/search/?q=', ['news', 'dev'], ['tech', 'english'], $plus),
            $this->eng('com.techcrunch.search', ['en' => 'TechCrunch'], 'https://techcrunch.com/?s=', ['news', 'dev'], ['tech', 'english'], $plus),
        ];
    }

    /** @return list<SeedEntry> Wikipedia je Sprache + weitere Nachschlagewerke. */
    private function referenceEngines(): array
    {
        $plus = ['plus' => true];
        $out = [];

        $wikiLangs = [
            'de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'es' => 'Español',
            'it' => 'Italiano', 'nl' => 'Nederlands', 'pl' => 'Polski', 'pt' => 'Português',
            'ru' => 'Русский', 'ja' => '日本語', 'zh' => '中文', 'sv' => 'Svenska',
            'uk' => 'Українська', 'cs' => 'Čeština', 'fi' => 'Suomi', 'da' => 'Dansk',
            'no' => 'Norsk', 'tr' => 'Türkçe', 'ar' => 'العربية', 'ko' => '한국어',
            'hu' => 'Magyar', 'el' => 'Ελληνικά', 'he' => 'עברית', 'id' => 'Indonesia',
            'vi' => 'Tiếng Việt', 'ro' => 'Română', 'th' => 'ไทย', 'fa' => 'فارسی',
            'bg' => 'Български', 'hr' => 'Hrvatski', 'sr' => 'Српски', 'sk' => 'Slovenčina',
            'sl' => 'Slovenščina', 'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'et' => 'Eesti',
            'ca' => 'Català', 'eu' => 'Euskara', 'gl' => 'Galego', 'hi' => 'हिन्दी',
            'ms' => 'Bahasa Melayu', 'is' => 'Íslenska', 'ga' => 'Gaeilge', 'cy' => 'Cymraeg',
        ];
        foreach ($wikiLangs as $code => $native) {
            $out[] = $this->eng(
                "org.wikipedia.$code",
                // Einsprachig: die $code-Wikipedia ist genau diese Sprache,
                // nicht mehrsprachig – Name nur unter dem echten Sprachcode.
                [$code => "Wikipedia ($native)"],
                "https://$code.wikipedia.org/w/index.php?search=",
                ['reference'],
                ['wiki', 'encyclopedia', $code],
                $plus,
            );
        }

        $out[] = $this->eng('org.wiktionary.en', ['en' => 'Wiktionary (English)'], 'https://en.wiktionary.org/w/index.php?search=', ['reference'], ['dictionary', 'en'], $plus);
        $out[] = $this->eng('org.wiktionary.de', ['de' => 'Wiktionary (Deutsch)'], 'https://de.wiktionary.org/w/index.php?search=', ['reference'], ['dictionary', 'de'], $plus);
        $out[] = $this->eng('org.wikidata.search', 'Wikidata', 'https://www.wikidata.org/w/index.php?search=', ['reference'], ['data', 'wiki'], $plus);
        $out[] = $this->eng('com.wolframalpha.search', ['en' => 'Wolfram Alpha'], 'https://www.wolframalpha.com/input?i=', ['reference'], ['math', 'compute'], $plus);
        $out[] = $this->eng('com.britannica.search', ['en' => 'Encyclopædia Britannica'], 'https://www.britannica.com/search?query=', ['reference'], ['encyclopedia', 'en'], $plus);
        $out[] = $this->eng('gov.nih.pubmed', ['en' => 'PubMed'], 'https://pubmed.ncbi.nlm.nih.gov/?term=', ['reference'], ['academic', 'medicine'], $plus);
        $out[] = $this->eng('org.semanticscholar.search', ['en' => 'Semantic Scholar'], 'https://www.semanticscholar.org/search?q=', ['reference'], ['academic', 'papers'], $plus);
        $out[] = $this->eng('com.dictionaryleo.search', ['de' => 'LEO Wörterbuch'], 'https://dict.leo.org/englisch-deutsch/', ['reference'], ['dictionary', 'de', 'en']);
        $out[] = $this->eng('com.dwds.search', ['de' => 'DWDS'], 'https://www.dwds.de/?q=', ['reference'], ['dictionary', 'de'], $plus);

        return $out;
    }

    /** @return list<SeedEntry> */
    private function devEngines(): array
    {
        $plus = ['plus' => true];

        return [
            $this->eng('com.github.search', 'GitHub', 'https://github.com/search?q=', ['dev'], ['code', 'git'], $plus),
            $this->eng('com.gitlab.search', 'GitLab', 'https://gitlab.com/search?search=', ['dev'], ['code', 'git'], $plus),
            $this->eng('com.stackoverflow.search', 'Stack Overflow', 'https://stackoverflow.com/search?q=', ['dev'], ['code', 'qa'], $plus),
            $this->eng('org.mozilla.mdn', 'MDN Web Docs', 'https://developer.mozilla.org/en-US/search?q=', ['dev', 'reference'], ['web', 'docs'], $plus),
            $this->eng('com.npmjs.search', 'npm', 'https://www.npmjs.com/search?q=', ['dev'], ['javascript', 'packages'], $plus),
            $this->eng('org.pypi.search', 'PyPI', 'https://pypi.org/search/?q=', ['dev'], ['python', 'packages'], $plus),
            $this->eng('io.crates.search', 'crates.io', 'https://crates.io/search?q=', ['dev'], ['rust', 'packages'], $plus),
            $this->eng('org.packagist.search', 'Packagist', 'https://packagist.org/search/?q=', ['dev'], ['php', 'packages'], $plus),
            $this->eng('com.dockerhub.search', 'Docker Hub', 'https://hub.docker.com/search?q=', ['dev'], ['docker', 'images'], $plus),
            $this->eng('io.devdocs.search', 'DevDocs', 'https://devdocs.io/#q=', ['dev', 'reference'], ['docs'], $plus),
            $this->eng('com.caniuse.search', 'Can I use', 'https://caniuse.com/?search=', ['dev', 'reference'], ['web', 'compat'], $plus),
            $this->eng('com.symfony.search', 'Symfony Docs', 'https://symfony.com/search?q=', ['dev', 'reference'], ['php', 'docs'], $plus),
            $this->eng('net.php.manual', 'PHP Manual', 'https://www.php.net/manual-lookup.php?pattern=', ['dev', 'reference'], ['php', 'docs'], $plus),
            $this->eng('rs.docs.search', 'docs.rs', 'https://docs.rs/releases/search?query=', ['dev', 'reference'], ['rust', 'docs'], $plus),
            $this->eng('com.readthedocs.search', 'Read the Docs', 'https://readthedocs.org/search/?q=', ['dev', 'reference'], ['docs'], $plus),
            $this->eng('com.hasteroid.hackernews', 'Hacker News (Algolia)', 'https://hn.algolia.com/?q=', ['dev', 'news'], ['tech', 'discussion'], $plus),
            $this->eng('com.grep.app', 'grep.app', 'https://grep.app/search?q=', ['dev'], ['code', 'search'], $plus),
            $this->eng('com.bundlephobia.search', 'Bundlephobia', 'https://bundlephobia.com/package/', ['dev'], ['javascript', 'size']),
        ];
    }

    /** @return list<SeedEntry> */
    private function mediaEngines(): array
    {
        $plus = ['plus' => true];

        return [
            $this->eng('com.youtube.search', 'YouTube', 'https://www.youtube.com/results?search_query=', ['video'], ['video'], $plus),
            $this->eng('com.vimeo.search', 'Vimeo', 'https://vimeo.com/search?q=', ['video'], ['video'], $plus),
            $this->eng('com.dailymotion.search', 'Dailymotion', 'https://www.dailymotion.com/search/', ['video'], ['video']),
            $this->eng('tv.twitch.search', 'Twitch', 'https://www.twitch.tv/search?term=', ['video', 'entertainment'], ['streaming', 'games'], $plus),
            $this->eng('com.imdb.search', 'IMDb', 'https://www.imdb.com/find/?q=', ['video', 'reference'], ['movies', 'tv'], $plus),
            $this->eng('org.themoviedb.search', 'The Movie Database', 'https://www.themoviedb.org/search?query=', ['video', 'reference'], ['movies', 'tv'], $plus),
            $this->eng('com.soundcloud.search', 'SoundCloud', 'https://soundcloud.com/search?q=', ['entertainment'], ['music'], $plus),
            $this->eng('com.bandcamp.search', 'Bandcamp', 'https://bandcamp.com/search?q=', ['entertainment'], ['music'], $plus),
            $this->eng('com.genius.search', 'Genius', 'https://genius.com/search?q=', ['entertainment', 'reference'], ['music', 'lyrics'], $plus),
            $this->eng('com.discogs.search', 'Discogs', 'https://www.discogs.com/search/?q=', ['entertainment', 'reference'], ['music', 'records'], $plus),
            $this->eng('com.spotify.search', 'Spotify', 'https://open.spotify.com/search/', ['entertainment'], ['music']),
            $this->eng('com.giphy.search', 'GIPHY', 'https://giphy.com/search/', ['entertainment'], ['gifs']),
        ];
    }

    /** @return list<SeedEntry> Amazon je TLD + eBay + weitere Shops. */
    private function shoppingEngines(): array
    {
        $plus = ['plus' => true];
        $out = [];

        // TLD => [Regionsname, Sprache der Region] – jede Regional-Seite ist
        // einsprachig in ihrer Landessprache, nicht mehrsprachig.
        $amazonTlds = [
            'de' => ['Deutschland', 'de'], 'com' => ['US', 'en'], 'co.uk' => ['UK', 'en'],
            'fr' => ['France', 'fr'], 'it' => ['Italia', 'it'], 'es' => ['España', 'es'],
            'nl' => ['Nederland', 'nl'], 'pl' => ['Polska', 'pl'], 'se' => ['Sverige', 'sv'],
            'ca' => ['Canada', 'en'], 'com.au' => ['Australia', 'en'], 'co.jp' => ['Japan', 'ja'],
        ];
        foreach ($amazonTlds as $tld => [$region, $lang]) {
            $idTld = str_replace('.', '-', $tld);
            $out[] = $this->eng(
                "com.amazon.$idTld",
                [$lang => "Amazon ($region)"],
                "https://www.amazon.$tld/s?k=",
                ['shopping'],
                ['shopping', 'amazon'],
                $plus,
            );
        }

        $ebayTlds = [
            'de' => ['Deutschland', 'de'], 'com' => ['US', 'en'], 'co.uk' => ['UK', 'en'],
            'fr' => ['France', 'fr'], 'it' => ['Italia', 'it'],
        ];
        foreach ($ebayTlds as $tld => [$region, $lang]) {
            $idTld = str_replace('.', '-', $tld);
            $out[] = $this->eng(
                "com.ebay.$idTld",
                [$lang => "eBay ($region)"],
                "https://www.ebay.$tld/sch/i.html?_nkw=",
                ['shopping'],
                ['shopping', 'auction'],
                $plus,
            );
        }

        $out[] = $this->eng('de.idealo.search', ['de' => 'idealo'], 'https://www.idealo.de/preisvergleich/MainSearchProductCategory.html?q=', ['shopping'], ['preisvergleich', 'deutsch'], $plus);
        $out[] = $this->eng('de.geizhals.search', ['de' => 'Geizhals'], 'https://geizhals.de/?fs=', ['shopping'], ['preisvergleich', 'deutsch'], $plus);
        $out[] = $this->eng('com.etsy.search', 'Etsy', 'https://www.etsy.com/search?q=', ['shopping'], ['handmade'], $plus);
        $out[] = $this->eng('com.aliexpress.search', 'AliExpress', 'https://www.aliexpress.com/wholesale?SearchText=', ['shopping'], ['shopping'], $plus);
        $out[] = $this->eng('de.otto.search', ['de' => 'OTTO'], 'https://www.otto.de/suche/', ['shopping'], ['shopping', 'deutsch']);
        $out[] = $this->eng('com.ebaykleinanzeigen.search', ['de' => 'Kleinanzeigen'], 'https://www.kleinanzeigen.de/s-suchanfrage.html?keywords=', ['shopping'], ['classifieds', 'deutsch'], $plus);

        return $out;
    }

    /** @return list<SeedEntry> */
    private function socialEngines(): array
    {
        $plus = ['plus' => true];

        return [
            $this->eng('com.reddit.search', 'Reddit', 'https://www.reddit.com/search/?q=', ['social'], ['forum'], $plus),
            $this->eng('com.x.search', 'X (Twitter)', 'https://twitter.com/search?q=', ['social'], ['microblog'], $plus),
            $this->eng('com.linkedin.search', 'LinkedIn', 'https://www.linkedin.com/search/results/all/?keywords=', ['social'], ['professional'], $plus),
            $this->eng('com.pinterest.search', 'Pinterest', 'https://www.pinterest.com/search/pins/?q=', ['social'], ['images'], $plus),
            $this->eng('app.bsky.search', 'Bluesky', 'https://bsky.app/search?q=', ['social'], ['microblog'], $plus),
            $this->eng('social.mastodon.search', 'Mastodon', 'https://mastodon.social/search?q=', ['social'], ['fediverse'], $plus),
            $this->eng('com.tiktok.search', 'TikTok', 'https://www.tiktok.com/search?q=', ['social', 'video'], ['video'], $plus),
            $this->eng('com.threads.search', 'Threads', 'https://www.threads.net/search?q=', ['social'], ['microblog'], $plus),
        ];
    }

    /** @return list<SeedEntry> Bild- und Rückwärtssuchen (type: image). */
    private function imageEngines(): array
    {
        $img = ['type' => 'image'];

        return [
            $this->eng('com.tineye.reverse', 'TinEye', 'https://www.tineye.com/search?url=', ['search', 'reference'], ['reverse-image'], $img),
            $this->eng('com.yandex.images', 'Yandex Images', 'https://yandex.com/images/search?rpt=imageview&url=', ['search'], ['reverse-image'], $img),
            $this->eng('com.google.lens', 'Google Lens', 'https://lens.google.com/uploadbyurl?url=', ['search'], ['reverse-image'], $img),
            $this->eng('org.saucenao.search', 'SauceNAO', 'https://saucenao.com/search.php?db=999&url=', ['search'], ['reverse-image'], $img),
            $this->eng('moe.trace.search', 'trace.moe', 'https://trace.moe/?url=', ['search', 'entertainment'], ['reverse-image', 'anime'], $img),
            $this->eng('com.bing.images', 'Bing Images', 'https://www.bing.com/images/search?view=detailv2&iss=sbi&q=imgurl:', ['search'], ['reverse-image'], $img),
        ];
    }

    /** @return list<SeedEntry> Echte Site-Suchen: Tools, Wissen, Reise, Medien. */
    private function toolsEngines(): array
    {
        $plus = ['plus' => true];

        return [
            $this->eng('com.steampowered.search', 'Steam', 'https://store.steampowered.com/search/?term=', ['entertainment'], ['games'], $plus),
            $this->eng('org.archive.search', 'Internet Archive', 'https://archive.org/search?query=', ['reference'], ['archive', 'library'], $plus),
            $this->eng('com.goodreads.search', 'Goodreads', 'https://www.goodreads.com/search?q=', ['reference', 'entertainment'], ['books'], $plus),
            $this->eng('com.letterboxd.search', 'Letterboxd', 'https://letterboxd.com/search/', ['video', 'social'], ['movies']),
            $this->eng('org.openlibrary.search', 'Open Library', 'https://openlibrary.org/search?q=', ['reference'], ['books', 'library'], $plus),
            $this->eng('com.stackexchange.search', 'Stack Exchange', 'https://stackexchange.com/search?q=', ['reference', 'dev'], ['qa'], $plus),
            $this->eng('com.superuser.search', 'Super User', 'https://superuser.com/search?q=', ['dev'], ['qa', 'sysadmin'], $plus),
            $this->eng('com.askubuntu.search', 'Ask Ubuntu', 'https://askubuntu.com/search?q=', ['dev'], ['qa', 'linux'], $plus),
            $this->eng('com.serverfault.search', 'Server Fault', 'https://serverfault.com/search?q=', ['dev'], ['qa', 'sysadmin'], $plus),
            $this->eng('com.unsplash.search', 'Unsplash', 'https://unsplash.com/s/photos/', ['reference'], ['photos', 'stock']),
            $this->eng('com.pexels.search', 'Pexels', 'https://www.pexels.com/search/', ['reference'], ['photos', 'stock']),
            $this->eng('com.flickr.search', 'Flickr', 'https://www.flickr.com/search/?text=', ['social', 'reference'], ['photos'], $plus),
            $this->eng('com.google.fonts', 'Google Fonts', 'https://fonts.google.com/?query=', ['dev', 'reference'], ['fonts', 'design'], $plus),
            $this->eng('com.fontawesome.search', 'Font Awesome', 'https://fontawesome.com/search?q=', ['dev'], ['icons', 'design'], $plus),
            $this->eng('org.emojipedia.search', 'Emojipedia', 'https://emojipedia.org/search?q=', ['reference'], ['emoji'], $plus),
            $this->eng('com.wikihow.search', 'wikiHow', 'https://www.wikihow.com/wikiHowTo?search=', ['reference'], ['howto'], $plus),
            $this->eng('com.yelp.search', 'Yelp', 'https://www.yelp.com/search?find_desc=', ['other'], ['reviews', 'local'], $plus),
            $this->eng('com.tripadvisor.search', 'Tripadvisor', 'https://www.tripadvisor.com/Search?q=', ['other'], ['travel'], $plus),
            $this->eng('com.booking.search', 'Booking.com', 'https://www.booking.com/searchresults.html?ss=', ['other'], ['travel', 'hotels'], $plus),
            $this->eng('org.openstreetmap.search', 'OpenStreetMap', 'https://www.openstreetmap.org/search?query=', ['reference'], ['maps'], $plus),
            $this->eng('com.googlemaps.search', 'Google Maps', 'https://www.google.com/maps/search/', ['reference'], ['maps']),
            $this->eng('com.deepl.write', 'DeepL Write', 'https://www.deepl.com/write?text=', ['productivity', 'reference'], ['writing', 'grammar'], $plus),
            $this->eng('com.chatgpt.ask', 'ChatGPT', 'https://chatgpt.com/?q=', ['productivity'], ['ai'], $plus),
            $this->eng('com.perplexity.ask', 'Perplexity', 'https://www.perplexity.ai/search/?q=', ['productivity', 'search'], ['ai'], $plus),
        ];
    }

    // ---- Menüs ----------------------------------------------------------

    /** @return list<SeedEntry> */
    private function menus(): array
    {
        return array_merge(
            $this->searchMenus(),
            $this->devMenus(),
            $this->productivityMenus(),
            $this->mediaMenus(),
            $this->germanSiteMenus(),
            $this->shoppingMenus(),
            $this->socialMenus(),
            $this->appMenus(),
        );
    }

    /** @return list<SeedEntry> */
    private function searchMenus(): array
    {
        return [
            $this->menu('gestura.menu.search', ['en' => 'Search', 'de' => 'Suche'], [], [
                $this->srch('s-google', 'google'),
                $this->srch('s-brave', 'brave'),
                $this->srch('s-perplexity', 'perplexity'),
                $this->srch('s-duckduckgo', 'duckduckgo'),
                $this->srch('s-bing', 'bing'),
                $this->sep('s-sep1'),
                $this->srch('s-deepl', 'deepl'),
                $this->srch('s-wikipedia', 'wikipedia'),
            ], ['search'], ['search', 'quicklinks']),
            $this->menu('gestura.menu.shopping', 'Shopping', [], [
                $this->srch('sh-brave', 'brave'),
                $this->srch('sh-google', 'google'),
                $this->srch('sh-amazon', 'amazon'),
                $this->srch('sh-ebay', 'ebay'),
            ], ['shopping', 'search'], ['shopping', 'quicklinks']),
        ];
    }

    /** @return list<SeedEntry> */
    private function devMenus(): array
    {
        return [
            $this->menu('com.github.menu', 'GitHub', ['*github.com*'], [
                $this->link('gh-home', ['en' => 'Dashboard', 'de' => 'Dashboard'], 'house', 'https://github.com/dashboard'),
                $this->link('gh-notif', ['en' => 'Notifications', 'de' => 'Benachrichtigungen'], 'bell', 'https://github.com/notifications'),
                $this->link('gh-issues', 'Issues', 'circleDot', 'https://github.com/issues'),
                $this->link('gh-pulls', 'Pull Requests', 'gitPullRequest', 'https://github.com/pulls'),
                $this->sep('gh-sep1'),
                $this->link('gh-new', ['en' => 'New repository', 'de' => 'Neues Repository'], 'squarePen', 'https://github.com/new'),
                $this->link('gh-gists', 'Gists', 'fileText', 'https://gist.github.com/'),
                $this->link('gh-trending', ['en' => 'Trending', 'de' => 'Trends'], 'trendingUp', 'https://github.com/trending'),
                $this->link('gh-settings', ['en' => 'Settings', 'de' => 'Einstellungen'], 'settings', 'https://github.com/settings'),
            ], ['dev'], ['git', 'code'], ['homepage' => 'https://github.com/', 'icon' => 'github']),
            $this->menu('com.gitlab.menu', 'GitLab', ['*gitlab.com*'], [
                $this->link('gl-dash', 'Dashboard', 'house', 'https://gitlab.com/dashboard'),
                $this->link('gl-projects', ['en' => 'Projects', 'de' => 'Projekte'], 'folder', 'https://gitlab.com/dashboard/projects'),
                $this->link('gl-issues', 'Issues', 'circleDot', 'https://gitlab.com/dashboard/issues'),
                $this->link('gl-mrs', 'Merge Requests', 'gitPullRequest', 'https://gitlab.com/dashboard/merge_requests'),
                $this->link('gl-new', ['en' => 'New project', 'de' => 'Neues Projekt'], 'squarePen', 'https://gitlab.com/projects/new'),
            ], ['dev'], ['git', 'code'], ['homepage' => 'https://gitlab.com/']),
            $this->menu('com.stackoverflow.menu', 'Stack Overflow', ['*stackoverflow.com*'], [
                $this->link('so-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://stackoverflow.com/'),
                $this->link('so-questions', ['en' => 'Questions', 'de' => 'Fragen'], 'circleHelp', 'https://stackoverflow.com/questions'),
                $this->link('so-tags', 'Tags', 'tag', 'https://stackoverflow.com/tags'),
                $this->link('so-users', ['en' => 'Users', 'de' => 'Nutzer'], 'users', 'https://stackoverflow.com/users'),
            ], ['dev'], ['qa', 'code'], ['homepage' => 'https://stackoverflow.com/']),
        ];
    }

    /** @return list<SeedEntry> */
    private function productivityMenus(): array
    {
        return [
            $this->menu('com.google.gmail.menu', 'Gmail', ['*mail.google.com*'], [
                $this->link('gm-inbox', ['en' => 'Inbox', 'de' => 'Posteingang'], 'inbox', 'https://mail.google.com/mail/u/0/#inbox'),
                $this->link('gm-compose', ['en' => 'Compose', 'de' => 'Schreiben'], 'squarePen', 'https://mail.google.com/mail/u/0/#inbox?compose=new'),
                $this->link('gm-starred', ['en' => 'Starred', 'de' => 'Markiert'], 'star', 'https://mail.google.com/mail/u/0/#starred'),
                $this->link('gm-sent', ['en' => 'Sent', 'de' => 'Gesendet'], 'send', 'https://mail.google.com/mail/u/0/#sent'),
                $this->sep('gm-sep1'),
                $this->link('gm-spam', 'Spam', 'ban', 'https://mail.google.com/mail/u/0/#spam'),
                $this->link('gm-trash', ['en' => 'Trash', 'de' => 'Papierkorb'], 'trash2', 'https://mail.google.com/mail/u/0/#trash'),
            ], ['productivity'], ['email', 'google'], ['homepage' => 'https://mail.google.com/', 'icon' => 'mail']),
            $this->menu('com.microsoft.m365.menu', 'Microsoft 365', ['*office.com*', '*microsoft365.com*', '*cloud.microsoft*'], [
                $this->link('ms-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://www.office.com/'),
                $this->link('ms-outlook', 'Outlook', 'mail', 'https://outlook.office.com/mail/'),
                $this->link('ms-calendar', ['en' => 'Calendar', 'de' => 'Kalender'], 'calendar', 'https://outlook.office.com/calendar/'),
                $this->link('ms-onedrive', 'OneDrive', 'hardDrive', 'https://www.office.com/launch/onedrive'),
                $this->sep('ms-sep1'),
                $this->link('ms-word', 'Word', 'fileText', 'https://www.office.com/launch/word'),
                $this->link('ms-excel', 'Excel', 'layoutGrid', 'https://www.office.com/launch/excel'),
                $this->link('ms-teams', 'Teams', 'users', 'https://teams.microsoft.com/'),
            ], ['productivity'], ['office', 'microsoft'], ['homepage' => 'https://www.office.com/', 'icon' => 'layoutGrid']),
            $this->menu('com.notion.menu', 'Notion', ['*notion.so*'], [
                $this->link('no-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://www.notion.so/'),
                $this->link('no-search', ['en' => 'Search', 'de' => 'Suche'], 'search', 'https://www.notion.so/search'),
                $this->link('no-new', ['en' => 'New page', 'de' => 'Neue Seite'], 'squarePen', 'https://www.notion.so/new'),
            ], ['productivity'], ['notes'], ['homepage' => 'https://www.notion.so/']),
            $this->menu('com.openai.chatgpt.menu', 'ChatGPT', ['*chatgpt.com*', '*chat.openai.com*'], [
                $this->link('gpt-new', ['en' => 'New chat', 'de' => 'Neuer Chat'], 'squarePen', 'https://chatgpt.com/'),
                $this->link('gpt-explore', 'GPTs', 'layoutGrid', 'https://chatgpt.com/gpts'),
            ], ['productivity'], ['ai', 'chat'], ['homepage' => 'https://chatgpt.com/']),
        ];
    }

    /** @return list<SeedEntry> */
    private function mediaMenus(): array
    {
        return [
            $this->menu('com.youtube.menu', 'YouTube', ['*youtube.com*'], [
                $this->link('yt-home', ['en' => 'Home', 'de' => 'Startseite'], 'house', 'https://www.youtube.com/'),
                $this->link('yt-subs', ['en' => 'Subscriptions', 'de' => 'Abos'], 'inbox', 'https://www.youtube.com/feed/subscriptions'),
                $this->link('yt-history', ['en' => 'History', 'de' => 'Verlauf'], 'history', 'https://www.youtube.com/feed/history'),
                $this->link('yt-watchlater', ['en' => 'Watch later', 'de' => 'Später ansehen'], 'clock', 'https://www.youtube.com/playlist?list=WL'),
                $this->link('yt-liked', ['en' => 'Liked videos', 'de' => 'Mag ich'], 'thumbsUp', 'https://www.youtube.com/playlist?list=LL'),
                $this->sep('yt-sep1'),
                $this->link('yt-studio', 'YouTube Studio', 'settings', 'https://studio.youtube.com/'),
            ], ['video'], ['video', 'streaming'], ['homepage' => 'https://www.youtube.com/', 'icon' => 'youtube']),
            $this->menu('com.vimeo.menu', 'Vimeo', ['*vimeo.com*'], [
                $this->link('vim-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://vimeo.com/home'),
                $this->link('vim-feed', 'Feed', 'inbox', 'https://vimeo.com/feed'),
                $this->link('vim-watchlater', ['en' => 'Watch later', 'de' => 'Später ansehen'], 'clock', 'https://vimeo.com/watchlater'),
                $this->link('vim-upload', ['en' => 'Upload', 'de' => 'Hochladen'], 'squarePen', 'https://vimeo.com/upload'),
            ], ['video'], ['video'], ['homepage' => 'https://vimeo.com/']),
            $this->menu('com.twitch.menu', 'Twitch', ['*twitch.tv*'], [
                $this->link('tw-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://www.twitch.tv/'),
                $this->link('tw-following', ['en' => 'Following', 'de' => 'Folge ich'], 'heart', 'https://www.twitch.tv/directory/following'),
                $this->link('tw-browse', ['en' => 'Browse', 'de' => 'Entdecken'], 'layoutGrid', 'https://www.twitch.tv/directory'),
            ], ['video', 'entertainment'], ['streaming', 'games'], ['homepage' => 'https://www.twitch.tv/']),
            $this->menu('com.spotify.menu', 'Spotify', ['*open.spotify.com*'], [
                $this->link('sp-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://open.spotify.com/'),
                $this->link('sp-search', ['en' => 'Search', 'de' => 'Suchen'], 'search', 'https://open.spotify.com/search'),
                $this->link('sp-library', ['en' => 'Library', 'de' => 'Bibliothek'], 'layoutGrid', 'https://open.spotify.com/collection/playlists'),
            ], ['entertainment'], ['music'], ['homepage' => 'https://open.spotify.com/']),
        ];
    }

    /** @return list<SeedEntry> Menüs für die genannten deutschen Seiten. */
    private function germanSiteMenus(): array
    {
        return [
            $this->menu('de.golem.menu', ['de' => 'Golem.de'], ['*golem.de*'], [
                $this->link('go-home', ['en' => 'Home', 'de' => 'Startseite'], 'house', 'https://www.golem.de/'),
                $this->link('go-news', ['en' => 'News', 'de' => 'News'], 'newspaper', 'https://www.golem.de/news/'),
                $this->link('go-audio', 'Audio/Podcast', 'headphones', 'https://www.golem.de/audio/'),
                $this->link('go-jobs', 'Jobs', 'briefcase', 'https://jobs.golem.de/'),
            ], ['news', 'dev'], ['tech', 'deutsch'], ['homepage' => 'https://www.golem.de/']),
            $this->menu('de.tagesschau.menu', ['de' => 'tagesschau'], ['*tagesschau.de*'], [
                $this->link('ts-home', ['en' => 'Home', 'de' => 'Startseite'], 'house', 'https://www.tagesschau.de/'),
                $this->link('ts-inland', 'Inland', 'mapPin', 'https://www.tagesschau.de/inland'),
                $this->link('ts-ausland', 'Ausland', 'globe', 'https://www.tagesschau.de/ausland'),
                $this->link('ts-wirtschaft', 'Wirtschaft', 'trendingUp', 'https://www.tagesschau.de/wirtschaft'),
                $this->link('ts-livestream', 'Livestream', 'play', 'https://www.tagesschau.de/livestream'),
            ], ['news'], ['nachrichten', 'deutsch'], ['homepage' => 'https://www.tagesschau.de/']),
            $this->menu('de.spiegel.menu', ['de' => 'DER SPIEGEL'], ['*spiegel.de*'], [
                $this->link('sp-home', ['en' => 'Home', 'de' => 'Startseite'], 'house', 'https://www.spiegel.de/'),
                $this->link('sp-politik', 'Politik', 'landmark', 'https://www.spiegel.de/politik/'),
                $this->link('sp-wirtschaft', 'Wirtschaft', 'trendingUp', 'https://www.spiegel.de/wirtschaft/'),
                $this->link('sp-panorama', 'Panorama', 'globe', 'https://www.spiegel.de/panorama/'),
                $this->link('sp-netzwelt', 'Netzwelt', 'cpu', 'https://www.spiegel.de/netzwelt/'),
            ], ['news'], ['nachrichten', 'deutsch'], ['homepage' => 'https://www.spiegel.de/']),
            $this->menu('de.mydealz.menu', ['de' => 'mydealz'], ['*mydealz.de*'], [
                $this->link('md-hot', ['en' => 'Hot deals', 'de' => 'Heiße Deals'], 'flame', 'https://www.mydealz.de/hot'),
                $this->link('md-new', ['en' => 'New', 'de' => 'Neu'], 'sparkles', 'https://www.mydealz.de/new'),
                $this->link('md-discussed', ['en' => 'Discussed', 'de' => 'Diskutiert'], 'messageCircle', 'https://www.mydealz.de/discussed'),
                $this->link('md-vouchers', ['en' => 'Vouchers', 'de' => 'Gutscheine'], 'ticket', 'https://www.mydealz.de/gutscheine'),
            ], ['shopping'], ['deals', 'deutsch'], ['homepage' => 'https://www.mydealz.de/']),
            $this->menu('com.msn.menu', ['de' => 'MSN'], ['*msn.com*'], [
                $this->link('msn-home', ['en' => 'Home', 'de' => 'Startseite'], 'house', 'https://www.msn.com/de-de'),
                $this->link('msn-news', ['en' => 'News', 'de' => 'Nachrichten'], 'newspaper', 'https://www.msn.com/de-de/nachrichten'),
                $this->link('msn-weather', ['en' => 'Weather', 'de' => 'Wetter'], 'cloud', 'https://www.msn.com/de-de/wetter'),
                $this->link('msn-sport', 'Sport', 'trophy', 'https://www.msn.com/de-de/sport'),
                $this->link('msn-finance', ['en' => 'Finance', 'de' => 'Finanzen'], 'trendingUp', 'https://www.msn.com/de-de/finanzen'),
            ], ['news'], ['portal', 'deutsch'], ['homepage' => 'https://www.msn.com/de-de']),
            $this->menu('de.heise.menu', ['de' => 'heise online'], ['*heise.de*'], [
                $this->link('he-home', ['en' => 'Home', 'de' => 'Startseite'], 'house', 'https://www.heise.de/'),
                $this->link('he-news', 'News', 'newspaper', 'https://www.heise.de/newsticker/'),
                $this->link('he-ct', "c't", 'bookOpen', 'https://www.heise.de/ct/'),
                $this->link('he-security', 'Security', 'shield', 'https://www.heise.de/security/'),
            ], ['news', 'dev'], ['tech', 'deutsch'], ['homepage' => 'https://www.heise.de/']),
        ];
    }

    /** @return list<SeedEntry> */
    private function shoppingMenus(): array
    {
        return [
            $this->menu('com.amazon.menu', 'Amazon', ['*amazon.*'], [
                $this->link('amz-home', ['en' => 'Home', 'de' => 'Startseite'], 'house', 'https://www.amazon.de/'),
                $this->link('amz-cart', ['en' => 'Cart', 'de' => 'Warenkorb'], 'shoppingCart', 'https://www.amazon.de/gp/cart/view.html'),
                $this->link('amz-orders', ['en' => 'Orders', 'de' => 'Bestellungen'], 'package', 'https://www.amazon.de/gp/css/order-history'),
                $this->link('amz-wishlist', ['en' => 'Wish list', 'de' => 'Wunschzettel'], 'heart', 'https://www.amazon.de/hz/wishlist/ls'),
                $this->sep('amz-sep1'),
                $this->link('amz-deals', ['en' => 'Deals', 'de' => 'Angebote'], 'tag', 'https://www.amazon.de/gp/goldbox'),
                $this->link('amz-returns', ['en' => 'Returns', 'de' => 'Rücksendungen'], 'rotateCcw', 'https://www.amazon.de/gp/css/returns/homepage.html'),
            ], ['shopping'], ['shopping', 'amazon'], ['homepage' => 'https://www.amazon.de/', 'icon' => 'shoppingCart']),
            $this->menu('de.idealo.menu', ['de' => 'idealo'], ['*idealo.de*'], [
                $this->link('id-home', ['en' => 'Home', 'de' => 'Startseite'], 'house', 'https://www.idealo.de/'),
                $this->link('id-deals', ['en' => 'Deals', 'de' => 'Schnäppchen'], 'tag', 'https://www.idealo.de/preisvergleich/Schnaeppchen.html'),
            ], ['shopping'], ['preisvergleich', 'deutsch'], ['homepage' => 'https://www.idealo.de/']),
        ];
    }

    /** @return list<SeedEntry> Kompakte Navigations-Menüs für populäre Apps. */
    private function appMenus(): array
    {
        return [
            $this->menu('com.netflix.menu', 'Netflix', ['*netflix.com*'], [
                $this->link('nf-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://www.netflix.com/'),
                $this->link('nf-mylist', ['en' => 'My list', 'de' => 'Meine Liste'], 'heart', 'https://www.netflix.com/browse/my-list'),
                $this->link('nf-new', ['en' => 'New & popular', 'de' => 'Neu & beliebt'], 'sparkles', 'https://www.netflix.com/latest'),
            ], ['video', 'entertainment'], ['streaming'], ['homepage' => 'https://www.netflix.com/']),
            $this->menu('com.disneyplus.menu', 'Disney+', ['*disneyplus.com*'], [
                $this->link('dp-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://www.disneyplus.com/home'),
                $this->link('dp-watchlist', ['en' => 'Watchlist', 'de' => 'Merkliste'], 'heart', 'https://www.disneyplus.com/watchlist'),
            ], ['video', 'entertainment'], ['streaming'], ['homepage' => 'https://www.disneyplus.com/']),
            $this->menu('com.instagram.menu', 'Instagram', ['*instagram.com*'], [
                $this->link('ig-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://www.instagram.com/'),
                $this->link('ig-explore', ['en' => 'Explore', 'de' => 'Entdecken'], 'search', 'https://www.instagram.com/explore/'),
                $this->link('ig-reels', 'Reels', 'play', 'https://www.instagram.com/reels/'),
                $this->link('ig-direct', ['en' => 'Messages', 'de' => 'Nachrichten'], 'mail', 'https://www.instagram.com/direct/inbox/'),
            ], ['social'], ['photos'], ['homepage' => 'https://www.instagram.com/']),
            $this->menu('com.x.menu', 'X', ['*x.com*', '*twitter.com*'], [
                $this->link('x-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://x.com/home'),
                $this->link('x-explore', ['en' => 'Explore', 'de' => 'Entdecken'], 'search', 'https://x.com/explore'),
                $this->link('x-notifications', ['en' => 'Notifications', 'de' => 'Mitteilungen'], 'bell', 'https://x.com/notifications'),
                $this->link('x-messages', ['en' => 'Messages', 'de' => 'Nachrichten'], 'mail', 'https://x.com/messages'),
            ], ['social'], ['microblog'], ['homepage' => 'https://x.com/']),
            $this->menu('com.google.drive.menu', 'Google Drive', ['*drive.google.com*'], [
                $this->link('gd-my', ['en' => 'My Drive', 'de' => 'Meine Ablage'], 'hardDrive', 'https://drive.google.com/drive/my-drive'),
                $this->link('gd-shared', ['en' => 'Shared with me', 'de' => 'Für mich freigegeben'], 'users', 'https://drive.google.com/drive/shared-with-me'),
                $this->link('gd-recent', ['en' => 'Recent', 'de' => 'Zuletzt verwendet'], 'history', 'https://drive.google.com/drive/recent'),
                $this->link('gd-starred', ['en' => 'Starred', 'de' => 'Markiert'], 'star', 'https://drive.google.com/drive/starred'),
            ], ['productivity'], ['files', 'google'], ['homepage' => 'https://drive.google.com/']),
            $this->menu('me.proton.mail.menu', 'Proton Mail', ['*mail.proton.me*'], [
                $this->link('pm-inbox', ['en' => 'Inbox', 'de' => 'Posteingang'], 'inbox', 'https://mail.proton.me/u/0/inbox'),
                $this->link('pm-sent', ['en' => 'Sent', 'de' => 'Gesendet'], 'send', 'https://mail.proton.me/u/0/sent'),
                $this->link('pm-archive', ['en' => 'Archive', 'de' => 'Archiv'], 'package', 'https://mail.proton.me/u/0/archive'),
            ], ['productivity'], ['email', 'privacy'], ['homepage' => 'https://mail.proton.me/']),
            $this->menu('com.figma.menu', 'Figma', ['*figma.com*'], [
                $this->link('fig-recent', ['en' => 'Recent', 'de' => 'Zuletzt'], 'history', 'https://www.figma.com/files/recent'),
                $this->link('fig-drafts', ['en' => 'Drafts', 'de' => 'Entwürfe'], 'fileText', 'https://www.figma.com/files/drafts'),
                $this->link('fig-community', 'Community', 'users', 'https://www.figma.com/community'),
            ], ['dev', 'productivity'], ['design'], ['homepage' => 'https://www.figma.com/']),
            $this->menu('com.discord.menu', 'Discord', ['*discord.com*'], [
                $this->link('dc-app', ['en' => 'Open app', 'de' => 'App öffnen'], 'messageCircle', 'https://discord.com/channels/@me'),
                $this->link('dc-discover', ['en' => 'Discover', 'de' => 'Entdecken'], 'search', 'https://discord.com/servers'),
            ], ['social'], ['chat', 'community'], ['homepage' => 'https://discord.com/']),
            $this->menu('com.google.calendar.menu', 'Google Calendar', ['*calendar.google.com*'], [
                $this->link('gc-day', ['en' => 'Day', 'de' => 'Tag'], 'calendar', 'https://calendar.google.com/calendar/u/0/r/day'),
                $this->link('gc-week', ['en' => 'Week', 'de' => 'Woche'], 'calendar', 'https://calendar.google.com/calendar/u/0/r/week'),
                $this->link('gc-month', ['en' => 'Month', 'de' => 'Monat'], 'calendar', 'https://calendar.google.com/calendar/u/0/r/month'),
            ], ['productivity'], ['calendar', 'google'], ['homepage' => 'https://calendar.google.com/']),
            $this->menu('com.outlook.menu', 'Outlook.com', ['*outlook.live.com*', '*outlook.office.com*'], [
                $this->link('ol-mail', ['en' => 'Mail', 'de' => 'E-Mail'], 'mail', 'https://outlook.live.com/mail/0/'),
                $this->link('ol-calendar', ['en' => 'Calendar', 'de' => 'Kalender'], 'calendar', 'https://outlook.live.com/calendar/0/'),
                $this->link('ol-people', ['en' => 'People', 'de' => 'Kontakte'], 'users', 'https://outlook.live.com/people/0/'),
            ], ['productivity'], ['email', 'microsoft'], ['homepage' => 'https://outlook.live.com/']),
            $this->menu('com.trello.menu', 'Trello', ['*trello.com*'], [
                $this->link('tr-boards', ['en' => 'Boards', 'de' => 'Boards'], 'layoutGrid', 'https://trello.com/boards'),
                $this->link('tr-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://trello.com/'),
            ], ['productivity'], ['kanban', 'tasks'], ['homepage' => 'https://trello.com/']),
            $this->menu('com.whatsapp.menu', 'WhatsApp Web', ['*web.whatsapp.com*'], [
                $this->link('wa-open', ['en' => 'Open WhatsApp', 'de' => 'WhatsApp öffnen'], 'messageCircle', 'https://web.whatsapp.com/'),
            ], ['social'], ['chat', 'messaging'], ['homepage' => 'https://web.whatsapp.com/']),
        ];
    }

    /** @return list<SeedEntry> */
    private function socialMenus(): array
    {
        return [
            $this->menu('com.reddit.menu', 'Reddit', ['*reddit.com*'], [
                $this->link('rd-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://www.reddit.com/'),
                $this->link('rd-popular', ['en' => 'Popular', 'de' => 'Beliebt'], 'trendingUp', 'https://www.reddit.com/r/popular/'),
                $this->link('rd-all', 'r/all', 'layoutGrid', 'https://www.reddit.com/r/all/'),
                $this->link('rd-messages', ['en' => 'Messages', 'de' => 'Nachrichten'], 'mail', 'https://www.reddit.com/message/inbox/'),
            ], ['social'], ['forum'], ['homepage' => 'https://www.reddit.com/']),
            $this->menu('com.linkedin.menu', 'LinkedIn', ['*linkedin.com*'], [
                $this->link('li-home', ['en' => 'Home', 'de' => 'Start'], 'house', 'https://www.linkedin.com/feed/'),
                $this->link('li-network', ['en' => 'Network', 'de' => 'Netzwerk'], 'users', 'https://www.linkedin.com/mynetwork/'),
                $this->link('li-jobs', 'Jobs', 'briefcase', 'https://www.linkedin.com/jobs/'),
                $this->link('li-messages', ['en' => 'Messages', 'de' => 'Nachrichten'], 'mail', 'https://www.linkedin.com/messaging/'),
            ], ['social'], ['professional'], ['homepage' => 'https://www.linkedin.com/']),
        ];
    }
}
