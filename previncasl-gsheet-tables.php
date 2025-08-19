<?php
/**
 * Plugin Name: Previnca GSheet Tables
 * Description: Muestra datos de Google Sheets en tablas con búsqueda y paginación para Grupo Previnca.
 * Version: 1.0.0
 * Author: Santiago M. González345
 */


if (!defined('ABSPATH')) exit;

class GSheet_Table_Shortcode {
    const TRANSIENT_PREFIX = 'gst_cache_';

    public function __construct() {
        add_shortcode('gsheet_table', [$this, 'shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets() {
        // Estilos mínimos
        $css = '
        .gst-wrap{overflow-x:auto}
        .gst-controls{display:flex;gap:.75rem;align-items:center;margin:.5rem 0 .75rem}
        .gst-search{flex:1}
        .gst-search input{width:100%;padding:.5rem;border:1px solid #ddd;border-radius:6px}
        .gst-page-size{min-width:120px;padding:.4rem;border:1px solid #ddd;border-radius:6px}
        table.gst{border-collapse: collapse; width:100%}
        .gst th,.gst td{border:1px solid #e5e5e5; padding:.5rem; vertical-align:top}
        .gst thead th{background:#f7f7f7; font-weight:600}
        .gst-pagination{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:.75rem 0}
        .gst-pagination button{padding:.35rem .6rem;border:1px solid #ddd;background:#fff;border-radius:6px;cursor:pointer}
        .gst-pagination button[disabled]{opacity:.5;cursor:not-allowed}
        .gst-pagination .gst-page-indicator{margin-left:.25rem}
        ';
        wp_register_style('gst-inline', false);
        wp_enqueue_style('gst-inline');
        wp_add_inline_style('gst-inline', $css);

        // Script de búsqueda + paginación (vanilla)
        $js = '
        (function(){
          function normalize(s){ return (s||"").toString().toLowerCase(); }

          function setup(tableId){
            var root = document.querySelector("[data-gst-id=\'"+tableId+"\']");
            if(!root) return;
            var tbody = root.querySelector("tbody");
            var rows = Array.from(tbody.querySelectorAll("tr"));
            var searchInput = root.querySelector(".gst-search input");
            var pageSizeSelect = root.querySelector(".gst-page-size");
            var prevBtn = root.querySelector(".gst-prev");
            var nextBtn = root.querySelector(".gst-next");
            var indicator = root.querySelector(".gst-page-indicator");

            var pageSize = parseInt(pageSizeSelect.value || "10",10);
            var page = 1;
            var filtered = rows.slice();

            function apply(){
              var total = filtered.length;
              var totalPages = Math.max(1, Math.ceil(total / pageSize));
              if(page > totalPages) page = totalPages;
              if(page < 1) page = 1;

              // Hide all then show page slice
              rows.forEach(function(tr){ tr.style.display = "none"; });

              var start = (page-1)*pageSize;
              var end = start + pageSize;
              filtered.slice(start, end).forEach(function(tr){ tr.style.display = ""; });

              prevBtn.disabled = page <= 1;
              nextBtn.disabled = page >= totalPages;
              indicator.textContent = total ? ("Página "+page+" de "+totalPages+" — "+total+" filas") : "Sin resultados";
            }

            function doFilter(){
              var q = normalize(searchInput.value);
              if(!q){
                filtered = rows.slice();
              } else {
                filtered = rows.filter(function(tr){
                  return Array.from(tr.cells).some(function(td){
                    return normalize(td.textContent).includes(q);
                  });
                });
              }
              page = 1;
              apply();
            }

            searchInput.addEventListener("input", function(){ doFilter(); });
            pageSizeSelect.addEventListener("change", function(){
              pageSize = parseInt(pageSizeSelect.value||"10",10);
              page = 1; apply();
            });
            prevBtn.addEventListener("click", function(){ page--; apply(); });
            nextBtn.addEventListener("click", function(){ page++; apply(); });

            // Primera aplicación
            apply();
          }

          document.addEventListener("DOMContentLoaded", function(){
            document.querySelectorAll("[data-gst-id]").forEach(function(el){
              setup(el.getAttribute("data-gst-id"));
            });
          });
        })();
        ';
        wp_register_script('gst-inline', false);
        wp_enqueue_script('gst-inline');
        wp_add_inline_script('gst-inline', $js);
    }

    public function shortcode($atts = []) {
        $atts = shortcode_atts([
            'url'            => '',
            'gid'            => '',
            'cache_minutes'  => '10',
            'header'         => '1',
            'class'          => '',
            'limit'          => '0',
            // NUEVOS:
            'search'         => '1',   // 1/0 para mostrar input de búsqueda
            'page_size'      => '10',  // tamaño de página por defecto
            'page_size_opts' => '10,25,50,100', // opciones del selector
            'placeholder'    => 'Buscar...',
        ], $atts, 'gsheet_table');

        $url = trim($atts['url']);
        if (!$url) return '<em>GSheet Table: falta el parámetro <code>url</code>.</em>';

        $csv_url = $this->normalize_to_csv_url($url, $atts['gid']);

        $cache_minutes = max(0, intval($atts['cache_minutes']));
        $cache_key = self::TRANSIENT_PREFIX . md5($csv_url . '|' . $atts['header'] . '|' . $atts['limit']);

        if ($cache_minutes > 0) {
            $cached = get_transient($cache_key);
            if ($cached !== false) return $cached;
        }

        $response = wp_remote_get($csv_url, [
            'timeout' => 15,
            'headers' => ['Accept' => 'text/csv']
        ]);
        if (is_wp_error($response)) {
            return $this->render_error('No se pudo obtener el CSV: ' . esc_html($response->get_error_message()));
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return $this->render_error('Respuesta inválida del servidor (' . intval($status) . ').');
        }
        $body = wp_remote_retrieve_body($response);
        if ($body === '' || $body === null) {
            return $this->render_error('CSV vacío.');
        }

        $rows = $this->parse_csv($body);
        if (empty($rows)) {
            return $this->render_error('No se encontraron filas en el CSV.');
        }

        $use_header = ($atts['header'] === '1');
        $limit = max(0, intval($atts['limit']));
        if ($limit > 0) {
            if ($use_header && count($rows) > 1) {
                $rows = array_merge([ $rows[0] ], array_slice($rows, 1, $limit));
            } else {
                $rows = array_slice($rows, 0, $limit);
            }
        }

        $table_class = 'gst' . ($atts['class'] ? ' ' . sanitize_html_class($atts['class']) : '');
        $table_id = 'gst_' . substr(md5(uniqid('', true)), 0, 8);

        // Render
        ob_start();

        echo '<div class="gst-root" data-gst-id="'.esc_attr($table_id).'">';

        // Controles: búsqueda y page size
        echo '<div class="gst-controls">';
        if ($atts['search'] === '1') {
            echo '<div class="gst-search"><input type="search" placeholder="'.esc_attr($atts['placeholder']).'"></div>';
        } else {
            echo '<div style="flex:1"></div>';
        }
        $opts = array_filter(array_map('trim', explode(',', $atts['page_size_opts'])));
        $default_ps = intval($atts['page_size']) ?: 10;
        if (!in_array((string)$default_ps, $opts, true)) {
            array_unshift($opts, (string)$default_ps);
            $opts = array_unique($opts);
        }
        echo '<select class="gst-page-size">';
        foreach ($opts as $o) {
            $sel = ((string)$default_ps === trim($o)) ? ' selected' : '';
            echo '<option value="'.esc_attr(trim($o)).'"'.$sel.'>'.esc_html(trim($o)).' por página</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="gst-wrap">';
        echo '<table class="'.esc_attr($table_class).'">';

        if ($use_header) {
            $header_row = array_shift($rows);
            echo '<thead><tr>';
            foreach ($header_row as $cell) {
                echo '<th>' . esc_html($cell) . '</th>';
            }
            echo '</tr></thead>';
        }
        echo '<tbody>';
        foreach ($rows as $r) {
            if (!is_array($r) || count(array_filter($r, fn($v)=> trim((string)$v) !== '')) === 0) continue;
            echo '<tr>';
            foreach ($r as $cell) {
                echo '<td>' . esc_html($cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        // Paginación
        echo '<div class="gst-pagination">';
        echo '<button type="button" class="gst-prev">&laquo; Anterior</button>';
        echo '<button type="button" class="gst-next">Siguiente &raquo;</button>';
        echo '<span class="gst-page-indicator" aria-live="polite" style="margin-left:.5rem"></span>';
        echo '</div>';

        echo '</div>'; // .gst-root

        $html = ob_get_clean();

        if ($cache_minutes > 0) {
            set_transient($cache_key, $html, $cache_minutes * MINUTE_IN_SECONDS);
        }

        return $html;
    }

    private function render_error($msg) {
        return '<div class="gst-error" style="border:1px solid #f2d7d5;background:#fdecea;padding:.5rem 1rem;color:#6a1a1a;">' . esc_html($msg) . '</div>';
    }

    private function normalize_to_csv_url($url, $gid) {
        if (strpos($url, '/pubhtml') !== false) {
            $url = preg_replace('#/pubhtml#', '/pub', $url);
        }
        if (strpos($url, 'output=csv') === false) {
            $url = $this->add_query_arg_compat($url, 'output', 'csv');
        }
        if ($gid !== '') {
            $url = $this->add_query_arg_compat($url, 'gid', $gid);
        }
        return $url;
    }

    private function add_query_arg_compat($url, $key, $value) {
        $parts = wp_parse_url($url);
        $query = [];
        if (!empty($parts['query'])) parse_str($parts['query'], $query);
        $query[$key] = $value;

        $scheme   = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host     = $parts['host'] ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path     = $parts['path'] ?? '';
        $newQuery = http_build_query($query);
        $frag     = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $host . $port . $path . ($newQuery ? '?' . $newQuery : '') . $frag;
    }

    private function parse_csv($csv_text) {
        $csv_text = str_replace(["\r\n", "\r"], "\n", $csv_text);
        $lines = explode("\n", $csv_text);
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }
}

new GSheet_Table_Shortcode();
