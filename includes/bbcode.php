<?php
/**
 * Parse BBCode markup and return HTML-safe output.
 *
 * @param string $text       Raw BBCode input (will be htmlspecialchars-escaped internally).
 * @param bool   $javascript Set true to enable [latex]...[/latex] rendering via MathJax.
 *                           Only pass true when MathJax is loaded on the page (forum section 8 /
 *                           sujet.php when $sujet['idforum'] == 8). All other contexts must omit
 *                           this parameter (defaults to false) so that [latex] tags are stripped
 *                           safely instead of being emitted as raw $$...$$ markup.
 */
function BBCode($text,$javascript=false){

$text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

$text = preg_replace('!localStorage.getItem\(("|\')mdp!isU', '', $text);
$text = preg_replace('!0:(-)?\)!isU', '<img alt="angel" src="images/smileys/icon_angel.gif"/>', $text);
$text = preg_replace('!\[b\](.+)\[/b\]!isU', '<span style="font-weight: bold">$1</span>', $text);
$text = preg_replace('!\[elfique\](.+)\[/elfique\]!isU', '<span style="font-family: quenya;font-size:2em">$1</span>', $text);
$text = preg_replace('!\[i\](.+)\[/i\]!isU', '<span style="font-style: italic">$1</span>', $text);
$text = preg_replace('!\[u\](.+)\[/u\]!isU', '<span style="text-decoration:underline;">$1</span>', $text);
$text = preg_replace('!\[sup\](.+)\[/sup\]!isU', '<sup>$1</sup>', $text);
$text = preg_replace('!\[sub\](.+)\[/sub\]!isU', '<sub>$1</sub>', $text);
$text = preg_replace('!\[center\](.+)\[/center\]!isU', '<div style="text-align: center;">$1</div>', $text);
$text = preg_replace('!\[title\](.+)\[/title\]!isU', '<span style="font-size: 130%;">$1</span>', $text);
$text = preg_replace('!\[joueur=([a-z0-9_-]{3,20})/\]!isU', '<a href="joueur.php?id=$1">$1</a>', $text);
$text = preg_replace('!\[alliance=([a-z0-9_-]{3,16})/\]!isU', '<a href="alliance.php?id=$1">$1</a>', $text);
// PASS4-LOW-010: Tightened URL character class to exclude spaces and unsafe chars (matches [img] restriction pattern)
$text = preg_replace('!\[url=(https?://[^\]\s"<>\']+)\](.+?)\[/url\]!isU', '<a href="$1" rel="nofollow noopener noreferrer" target="_blank">$2</a>', $text);
$text = preg_replace_callback('!\[img=([^\]]+)\]!isU', function($matches) {
    $url = $matches[1];
    // Allow self-hosted images: relative paths starting with / or images/
    // Allow theverylittlewar.com absolute URLs
    if (preg_match('#^(images/[^\s"<>]+\.(?:gif|png|jpg|jpeg))$#i', $url)
        || preg_match('#^(/[^\s"<>]+\.(?:gif|png|jpg|jpeg))$#i', $url)
        || preg_match('#^https?://(?:www\.)?theverylittlewar\.com/[^\s"<>]+\.(?:gif|png|jpg|jpeg)$#i', $url)) {
        return '<img alt="image" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    }
    return '[Image externe bloquée]';
}, $text);
$text = preg_replace('!\[color=(blue|red|green|white|black|beige|brown|cyan|yellow|orange|gray|purple|maroon)\](.+)\[/color\]!isU', '<span style="color:$1;">$2</span>', $text);

if ($javascript) {
    $text = preg_replace_callback('!\[latex\](.+)\[/latex\]!isU', function($m) {
        // HIGH-008: Block all LaTeX macro-definition and external-resource commands to prevent XSS/RCE
        $blacklist = ['href', 'url', 'unicode', 'cssId', 'class', 'def', 'let', 'gdef', 'edef', 'newcommand', 'renewcommand', 'catcode', 'input', 'include', 'csname', 'expandafter', 'require', 'write', 'special'];
        $latex = preg_replace('/\\\\(' . implode('|', $blacklist) . ')\b/i', '\\text{blocked}', $m[1]);
        return '$$' . $latex . '$$';
    }, $text);
} else {
    // [latex] tag only renders when MathJax is loaded (forum section 8); strip it elsewhere
    $text = preg_replace('!\[latex\](.+)\[/latex\]!isU', '[formule]', $text);
}

$text = preg_replace('!:arrow:!isU', '→', $text);
$text = preg_replace('!:(-)?D!isU', '😃', $text);
$text = preg_replace('!xD!isU', '😆', $text);
$text = preg_replace('!:(-)?s!isU', '😖', $text);
$text = preg_replace('!B(-)?\)!isU', '😎', $text);
$text = preg_replace('!:\'(-)?\(!isU', '😢', $text);
$text = preg_replace('!O_o!sU', '😮', $text);
$text = preg_replace('!o_O!sU', '😮', $text);
$text = preg_replace('!3:(-)?\)!isU', '😈', $text);
$text = preg_replace('!:idea:!isU', '💡', $text);
$text = preg_replace('!\blol\b!isU', '😁', $text);
$text = preg_replace('!(?<![=<>/])=/(?![=>])!sU', '😕', $text);
$text = preg_replace('!:green:!isU', '😷', $text);
$text = preg_replace('!:(-)?(\||l)!isU', '😐', $text);
$text = preg_replace('!:(-)?p!isU', '😛', $text);
$text = preg_replace('!:emotion:!isU', '😳', $text);
$text = preg_replace('!8(-)?\(!isU', '🙄', $text);
$text = preg_replace('!:(-)?\(!isU', '😟', $text);
$text = preg_replace('!:(-)?\)!isU', '😊', $text);
$text = preg_replace('!:(-)?o!isU', '😲', $text);
$text = preg_replace('!;(-)?\)!isU', '😉', $text);
$text = preg_replace('!:chainhappy:!isU', '<img alt="chainhappy" src="images/smileys/chainhappy.gif"/>', $text);
$text = preg_replace('!:want:!isU', '<img alt="want" src="images/smileys/want.gif"/>', $text);
$text = preg_replace('!:facepalm:!isU', '<img alt="facepalm" src="images/smileys/facepalm.gif"/>', $text);
$text = preg_replace('!:bye:!isU', '<img alt="bye" src="images/smileys/bye.gif"/>', $text);
$text = preg_replace('!:music:!isU', '<img alt="music" src="images/smileys/music.gif"/>', $text);
$text = preg_replace('!:what:!isU', '<img alt="what" src="images/smileys/what.gif"/>', $text);
return $text;
}
?>
