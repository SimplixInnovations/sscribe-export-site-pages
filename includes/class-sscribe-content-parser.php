<?php
/**
 * Parses HTML content into structured elements for DOCX generation.
 *
 * @package SScribe
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SScribe_Content_Parser
 *
 * Converts rendered HTML page content into a structured array
 * of elements that the Exporter can turn into PHPWord objects.
 */
class SScribe_Content_Parser
{

    /**
     * Parse HTML content into structured elements.
     *
     * @param string $html The rendered HTML content.
     * @return array Array of element arrays.
     */
    public function parse($html)
    {
        if (empty($html)) {
            return array();
        }

        // Strip shortcode markers.
        $html = $this->strip_shortcodes($html);

        // Normalize HTML.
        $html = $this->normalize_html($html);

        // Parse DOM.
        $elements = $this->parse_dom($html);

        return $elements;
    }

    /**
     * Strip remaining shortcodes and add placeholder marker.
     *
     * @param string $html The HTML content.
     * @return string Cleaned HTML.
     */
    private function strip_shortcodes($html)
    {
        // Replace unprocessed shortcodes with a marker.
        $html = preg_replace('/\[(\/?[a-zA-Z0-9_-]+)[^\]]*\]/', '', $html);
        return $html;
    }

    /**
     * Normalize HTML for consistent parsing.
     *
     * @param string $html The HTML content.
     * @return string Normalized HTML.
     */
    private function normalize_html($html)
    {
        // Convert common entities.
        $html = wp_kses_post($html);

        // Remove excessive whitespace between tags.
        $html = preg_replace('/>\s+</', '><', $html);

        // Ensure proper block-level element separation.
        $html = preg_replace('/<\/(p|div|h[1-6]|ul|ol|li|table|tr|blockquote|pre)>/', "</\\1>\n", $html);

        return trim($html);
    }

    /**
     * Parse HTML DOM into structured element array.
     *
     * @param string $html The HTML content.
     * @return array Array of elements.
     */
    private function parse_dom($html)
    {
        $elements = array();

        // Use DOMDocument for reliable parsing.
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Suppress warnings from malformed HTML.
        libxml_use_internal_errors(true);

        // Wrap content to ensure proper encoding.
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $elements;
        }

        // Process child nodes.
        foreach ($body->childNodes as $node) {
            $parsed = $this->parse_node($node);
            if ($parsed) {
                if (isset($parsed['type'])) {
                    $elements[] = $parsed;
                } else {
                    // Array of elements returned.
                    $elements = array_merge($elements, $parsed);
                }
            }
        }

        return $elements;
    }

    /**
     * Parse a DOM node into a structured element.
     *
     * @param DOMNode $node The DOM node.
     * @param int     $depth Nesting depth for lists.
     * @return array|null Element data or null.
     */
    private function parse_node($node, $depth = 0)
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->textContent);
            if (!empty($text)) {
                return array(
                    'type' => 'paragraph',
                    'content' => $text,
                    'runs' => array(array('text' => $text)),
                );
            }
            return null;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return null;
        }

        $tag_name = strtolower($node->nodeName);

        switch ($tag_name) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                return array(
                    'type' => 'heading',
                    'level' => (int) substr($tag_name, 1),
                    'content' => $this->get_text_content($node),
                    'runs' => $this->get_inline_runs($node),
                );

            case 'p':
                $runs = $this->get_inline_runs($node);
                if (empty($runs)) {
                    return null;
                }

                // Check if this is a button paragraph.
                $button = $this->detect_button($node);
                if ($button) {
                    return $button;
                }

                return array(
                    'type' => 'paragraph',
                    'content' => $this->get_text_content($node),
                    'runs' => $runs,
                );

            case 'ul':
                return $this->parse_list($node, 'bullet', $depth);

            case 'ol':
                return $this->parse_list($node, 'numbered', $depth);

            case 'blockquote':
                return array(
                    'type' => 'blockquote',
                    'content' => $this->get_text_content($node),
                    'runs' => $this->get_inline_runs($node),
                );

            case 'pre':
            case 'code':
                return array(
                    'type' => 'code',
                    'content' => $node->textContent,
                );

            case 'table':
                return $this->parse_table($node);

            case 'img':
                return $this->parse_image($node);

            case 'a':
                // Standalone link (not inside a paragraph).
                $href = $node->getAttribute('href');
                $text = $this->get_text_content($node);
                return array(
                    'type' => 'paragraph',
                    'content' => $text,
                    'runs' => array(
                        array(
                            'text' => $text,
                            'link' => $href,
                        ),
                    ),
                );

            case 'div':
            case 'section':
            case 'article':
            case 'main':
            case 'aside':
            case 'figure':
            case 'figcaption':
                // Recurse into container elements.
                $children = array();
                foreach ($node->childNodes as $child) {
                    $parsed = $this->parse_node($child, $depth);
                    if ($parsed) {
                        if (isset($parsed['type'])) {
                            $children[] = $parsed;
                        } else {
                            $children = array_merge($children, $parsed);
                        }
                    }
                }
                return !empty($children) ? $children : null;

            case 'br':
                return array(
                    'type' => 'break',
                    'content' => '',
                );

            case 'hr':
                return array(
                    'type' => 'horizontal_rule',
                    'content' => '',
                );

            default:
                // Try to extract text from unknown elements.
                $text = trim($node->textContent);
                if (!empty($text)) {
                    return array(
                        'type' => 'paragraph',
                        'content' => $text,
                        'runs' => array(array('text' => $text)),
                    );
                }
                return null;
        }
    }

    /**
     * Parse a list element (ul/ol) into structured items.
     *
     * @param DOMNode $node   The list node.
     * @param string  $style  'bullet' or 'numbered'.
     * @param int     $depth  Nesting depth.
     * @return array List element data.
     */
    private function parse_list($node, $style, $depth = 0)
    {
        $items = array();

        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $item = array(
                'content' => '',
                'runs' => array(),
                'children' => array(),
                'depth' => min($depth, 2), // Max 3 levels (0, 1, 2).
            );

            foreach ($child->childNodes as $li_child) {
                $li_tag = strtolower($li_child->nodeName);

                if ($li_tag === 'ul' || $li_tag === 'ol') {
                    // Nested list.
                    $nested_style = ($li_tag === 'ul') ? 'bullet' : 'numbered';
                    $nested = $this->parse_list($li_child, $nested_style, $depth + 1);
                    if (isset($nested['items'])) {
                        $item['children'] = $nested['items'];
                    }
                } else {
                    // Text content of this list item.
                    if ($li_child->nodeType === XML_TEXT_NODE) {
                        $text = trim($li_child->textContent);
                        if (!empty($text)) {
                            $item['runs'][] = array('text' => $text);
                        }
                    } elseif ($li_child->nodeType === XML_ELEMENT_NODE) {
                        $inline_runs = $this->get_inline_runs($li_child);
                        $item['runs'] = array_merge($item['runs'], $inline_runs);
                    }
                }
            }

            $item['content'] = $this->runs_to_text($item['runs']);
            $items[] = $item;
        }

        return array(
            'type' => 'list',
            'style' => $style,
            'items' => $items,
        );
    }

    /**
     * Parse an HTML table into structured data.
     *
     * @param DOMNode $node The table node.
     * @return array Table element data.
     */
    private function parse_table($node)
    {
        $rows = array();
        $is_header = true;

        // Get thead/tbody/direct tr children.
        $sections = array();
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'thead' || $tag === 'tbody' || $tag === 'tfoot') {
                foreach ($child->childNodes as $tr) {
                    if ($tr->nodeType === XML_ELEMENT_NODE && strtolower($tr->nodeName) === 'tr') {
                        $sections[] = array(
                            'tr' => $tr,
                            'is_header' => ($tag === 'thead'),
                        );
                    }
                }
            } elseif ($tag === 'tr') {
                $sections[] = array(
                    'tr' => $child,
                    'is_header' => false,
                );
            }
        }

        foreach ($sections as $index => $section) {
            $tr = $section['tr'];
            $cells = array();

            foreach ($tr->childNodes as $td) {
                if ($td->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                $cell_tag = strtolower($td->nodeName);
                if ($cell_tag === 'td' || $cell_tag === 'th') {
                    $cells[] = array(
                        'content' => trim($td->textContent),
                        'is_header' => ($cell_tag === 'th' || $section['is_header']),
                    );
                }
            }

            if (!empty($cells)) {
                $rows[] = array(
                    'cells' => $cells,
                    'is_header' => $section['is_header'] || ($index === 0 && $cells[0]['is_header']),
                );
            }
        }

        return array(
            'type' => 'table',
            'rows' => $rows,
        );
    }

    /**
     * Parse an image element.
     *
     * @param DOMNode $node The img node.
     * @return array|null Image element data or null.
     */
    private function parse_image($node)
    {
        $src = $node->getAttribute('src');
        $alt = $node->getAttribute('alt');

        if (empty($src)) {
            return null;
        }

        // Try to get local file path.
        $local_path = $this->url_to_local_path($src);

        return array(
            'type' => 'image',
            'src' => $src,
            'alt' => $alt,
            'local_path' => $local_path,
        );
    }

    /**
     * Detect button-like elements inside a paragraph.
     *
     * @param DOMNode $node The paragraph node.
     * @return array|null Button element data or null.
     */
    private function detect_button($node)
    {
        // Look for links with button-like classes.
        $links = $node->getElementsByTagName('a');

        foreach ($links as $link) {
            $classes = $link->getAttribute('class');
            if ($this->is_button_class($classes)) {
                return array(
                    'type' => 'button',
                    'content' => trim($link->textContent),
                    'url' => $link->getAttribute('href'),
                );
            }
        }

        // Check for actual button elements.
        $buttons = $node->getElementsByTagName('button');
        foreach ($buttons as $button) {
            return array(
                'type' => 'button',
                'content' => trim($button->textContent),
                'url' => '',
            );
        }

        return null;
    }

    /**
     * Check if CSS classes indicate a button.
     *
     * @param string $classes The class attribute value.
     * @return bool
     */
    private function is_button_class($classes)
    {
        $button_patterns = array(
            'wp-block-button__link',
            'wp-element-button',
            'button',
            'btn',
            'elementor-button',
            'et_pb_button',
            'fl-button',
            'vc_btn',
        );

        foreach ($button_patterns as $pattern) {
            if (strpos($classes, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get inline runs (text with formatting) from a node.
     *
     * @param DOMNode $node The container node.
     * @return array Array of run data (text, bold, italic, link, etc.).
     */
    private function get_inline_runs($node)
    {
        $runs = array();

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $child->textContent;
                if ($text !== '') {
                    $runs[] = array('text' => $text);
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);

                switch ($tag) {
                    case 'strong':
                    case 'b':
                        $sub_runs = $this->get_inline_runs($child);
                        foreach ($sub_runs as &$run) {
                            $run['bold'] = true;
                        }
                        $runs = array_merge($runs, $sub_runs);
                        break;

                    case 'em':
                    case 'i':
                        $sub_runs = $this->get_inline_runs($child);
                        foreach ($sub_runs as &$run) {
                            $run['italic'] = true;
                        }
                        $runs = array_merge($runs, $sub_runs);
                        break;

                    case 'u':
                        $sub_runs = $this->get_inline_runs($child);
                        foreach ($sub_runs as &$run) {
                            $run['underline'] = true;
                        }
                        $runs = array_merge($runs, $sub_runs);
                        break;

                    case 's':
                    case 'del':
                    case 'strike':
                        $sub_runs = $this->get_inline_runs($child);
                        foreach ($sub_runs as &$run) {
                            $run['strikethrough'] = true;
                        }
                        $runs = array_merge($runs, $sub_runs);
                        break;

                    case 'a':
                        $href = $child->getAttribute('href');
                        $sub_runs = $this->get_inline_runs($child);
                        foreach ($sub_runs as &$run) {
                            $run['link'] = $href;
                        }
                        $runs = array_merge($runs, $sub_runs);
                        break;

                    case 'code':
                        $runs[] = array(
                            'text' => $child->textContent,
                            'code' => true,
                        );
                        break;

                    case 'br':
                        $runs[] = array('text' => "\n", 'break' => true);
                        break;

                    case 'span':
                        // Recurse into spans.
                        $sub_runs = $this->get_inline_runs($child);
                        $runs = array_merge($runs, $sub_runs);
                        break;

                    case 'img':
                        // Inline image reference.
                        $src = $child->getAttribute('src');
                        if ($src) {
                            $runs[] = array(
                                'text' => '[Image: ' . $child->getAttribute('alt') . ']',
                                'image' => $src,
                            );
                        }
                        break;

                    default:
                        // Extract text from unknown inline elements.
                        $sub_runs = $this->get_inline_runs($child);
                        $runs = array_merge($runs, $sub_runs);
                        break;
                }
            }
        }

        return $runs;
    }

    /**
     * Get plain text content from a node.
     *
     * @param DOMNode $node The node.
     * @return string Plain text content.
     */
    private function get_text_content($node)
    {
        return trim($node->textContent);
    }

    /**
     * Convert runs array to plain text.
     *
     * @param array $runs Array of run data.
     * @return string Plain text.
     */
    private function runs_to_text($runs)
    {
        $text = '';
        foreach ($runs as $run) {
            $text .= isset($run['text']) ? $run['text'] : '';
        }
        return trim($text);
    }

    /**
     * Try to convert a URL to a local file path.
     *
     * @param string $url The image URL.
     * @return string Local file path or empty string.
     */
    private function url_to_local_path($url)
    {
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        $upload_path = $upload_dir['basedir'];

        if (strpos($url, $upload_url) === 0) {
            $relative = str_replace($upload_url, '', $url);
            $local = $upload_path . $relative;
            if (file_exists($local)) {
                return $local;
            }
        }

        return '';
    }
}
