<?php
/**
 * Plugin Name: PrevincaSL GSheet Tables
 * Description: Muestra datos de Google Sheets en tablas con búsqueda, filtro y paginación para Grupo Previnca.
 * Version: 1.0.0
 * Author: Santiago M. González
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
        .gst-root{--gst-gap:.75rem}
        .gst-wrap{overflow-x:auto}
        .gst-controls{display:flex;gap:var(--gst-gap);align-items:stretch;flex-wrap:wrap;margin:.5rem 0 .75rem}
        .gst-search{flex:1;min-width:220px}
        .gst-search input{width:100%;padding:.5rem;border:1px solid #ddd;border-radius:6px}
        .gst-page-size{min-width:140px;padding:.45rem;border:1px solid #ddd;border-radius:6px}
        .gst-filter-cols{display:flex;gap:.5rem;align-items:center;min-width:240px}
        .gst-filter-cols label{font-size:.9rem;white-space:nowrap}
        .gst-cols{min-width:200px;min-height:2.3rem;border:1px solid #ddd;border-radius:6px;padding:.2rem}
        .gst-cols[multiple]{height:auto}
        .gst-filter-actions{display:flex;gap:.35rem}
        .gst-filter-actions button{padding:.35rem .6rem;border:1px solid #ddd;background:#fff;border-radius:6px;cursor:pointer}
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

        // Script: búsqueda + paginación + filtro por columnas (vanilla)
        $js = '
        (function(){
          function normalize(s){ return (s||"").toString().toLowerCase(); }

          function setup(tableId){
            var root = document.querySelector("[data-gst-id=\'"+tableId+"\']");
            if(!root) return;
            var tbody = root.querySelector("tbody");
            var rows  = Array.from(tbody.querySelectorAll("tr"));
            var searchInput  = root.querySelector(".gst-search input");
            var pageSizeSel  = root.querySelector(".gst-page-size");
            var prevBtn      = root.querySelector(".gst-prev");
            var nextBtn      = root.querySelector(".gst-next");
            var indicator    = root.querySelector(".gst-page-indicator");
            var colsSelect   = root.querySelector(".gst-cols");
            var btnAll       = root.querySelector(".gst-cols-all");
            var btnNone      = root.querySelector(".gst-cols-none");

            var pageSize = parseInt(pageSizeSel.value || "10", 10);
            var page = 1;
            var filtered = rows.slice();

            function selectedCols(){
              // Devuelve Set con índices de columnas seleccionadas
              var set = new Set();
              Array.from(colsSelect.options).forEach(function(opt, idx){
                if(opt.selected){ set.add(parseInt(opt.value,10)); }
              });
              // Si ninguna columna está seleccionada: no coincidiremos nada; para UX, usar todas
              if(set.size === 0){
                // fallback: usar todas
                var maxCells = Math.max.apply(null, rows.map(function(tr){ return tr.cells.length; }));
                for(var i=0;i<maxCells;i++) set.add(i);
              }
              return set;
            }

            function apply(){
              var total = filtered.length;
              var totalPages = Math.max(1, Math.ceil(total / pageSize));
              if(page > totalPages) page = totalPages;
              if(page < 1) page = 1;

              rows.forEach(function(tr){ tr.style.display = "none"; });

              var start = (page-1)*pageSize;
              var end   = start + pageSize;
              filtered.slice(start, end).forEach(function(tr){ tr.style.display = ""; });

              prevBtn.disabled = page <= 1;
              nextBtn.disabled = page >= totalPages;
              indicator.textContent = total ? ("Página "+page+" de "+totalPages+" — "+total+" filas") : "Sin resultados";
            }

            function doFilter(){
              var q = normalize(searchInput.value);
              var cols = selectedCols();
              if(!q){
                filtered = rows.slice();
              } else {
                filtered = rows.filter(function(tr){
                  var cells = Array.from(tr.cells);
                  for(var i=0;i<cells.length;i++){
                    if(!cols.has(i)) continue;
                    if(normalize(cells[i].textContent).includes(q)) return true;
                  }
                  return false;
                });
              }
              page = 1;
              apply();
            }

            searchInput.addEventListener("input", doFilter);
            pageSizeSel.addEventListener("change", function(){
              pageSize = parseInt(pageSizeSel.value||"10",10);
              page = 1; apply();
            });
            prevBtn.addEventListener("click", function(){ page--; apply(); });
            nextBtn.addEventListener("click", function(){ page++; apply(); });

            colsSelect.addEventListener("change", doFilter);
            if(btnAll) btnAll.addEventListener("click", function(){
              Array.from(colsSelect.options).forEach(function(o){ o.selected = true; });
              doFilter();
            });
            if(btnNone) btnNone.addEventListener("click", function(){
              Array.from(colsSelect.options).forEach(function(o){ o.selected = false; });
              doFilter();
            });

            apply(); // primera render
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
            // UI:
            'search'         => '1',
            'page_size'      => '10',
            'page_size_opts' => '10,25,50,100',
            'placeholder'    => 'Buscar...',
        ], $atts, 'gsheet_table');

        $url = trim($atts['url']);
        if (!$url) return '<em>GSheet Table: falta el parámetro <code>url</code>.</em>';

        $csv_url = $this->normalize_to_csv_url($url, $atts['gid']);

        $cache_minutes = max(0, intval($atts['cache_minutes']));
        $cache_key = self::TRANSIENT_PREFIX . md5($csv_url . "|" . $atts['header'] . "|" . $atts['limit'] . "|" . $atts['page_size'] . "|" . $atts['page_size_opts'] . "|" . $atts['search']);

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

        // LIMIT antes de separar header
        $limit = max(0, intval($atts['limit']));
        if ($limit > 0) {
            if ($use_header && count($rows) > 1) {
                $rows = array_merge([ $rows[0] ], array_slice($rows, 1, $limit));
            } else {
                $rows = array_slice($rows, 0, $limit);
            }
        }

        // Construcción de títulos de columnas para el selector
        $header_labels = [];
        if ($use_header) {
            $header_labels = $rows[0];
        } else {
            // Si no hay header, usamos la primera fila de datos para calcular cantidad de columnas
            $sample = $rows[0];
            $cols = is_array($sample) ? count($sample) : 0;
            for ($i=0; $i<$cols; $i++) $header_labels[] = 'Col ' . ($i+1);
        }

        $table_class = 'gst' . ($atts['class'] ? ' ' . sanitize_html_class($atts['class']) : '');
        $table_id = 'gst_' . substr(md5(uniqid('', true)), 0, 8);

        ob_start();

        echo '<div class="gst-root" data-gst-id="'.esc_attr($table_id).'">';

        // Controles
        echo '<div class="gst-controls">';
        // Búsqueda
        if ($atts['search'] === '1') {
            echo '<div class="gst-search"><input type="search" placeholder="'.esc_attr($atts['placeholder']).'"></div>';
        } else {
            echo '<div style="flex:1"></div>';
        }
        // Selector de columnas (multiselección)
        echo '<div class="gst-filter-cols">';
        echo '<label>Columnas:</label>';
        echo '<select class="gst-cols" multiple size="'.max(3, min(8, count($header_labels))).'">';
        foreach ($header_labels as $idx => $lbl) {
            $txt = trim($lbl) === "" ? ("Col ".($idx+1)) : $lbl;
            echo '<option value="'.esc_attr($idx).'" selected>'.esc_html($txt).'</option>';
        }
        echo '</select>';
        echo '<span class="gst-filter-actions">';
        echo '<button type="button" class="gst-cols-all">Todas</button>';
        echo '<button type="button" class="gst-cols-none">Ninguna</button>';
        echo '</span>';
        echo '</div>';

        // Page size
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

        echo '</div>'; // .gst-controls

        // Tabla
        echo '<div class="gst-wrap">';
        echo '<table class="'.esc_attr($table_class).'">';

        if ($use_header) {
            $header_row = array_shift($rows); // ya recortada en parse_csv (sin col vacías a derecha)
            echo '<thead><tr>';
            foreach ($header_row as $cell) {
                echo '<th>' . esc_html($cell) . '</th>';
            }
            echo '</tr></thead>';
        }
        echo '<tbody>';
        foreach ($rows as $r) {
            if (!is_array($r) || count(array_filter($r, fn($v)=> trim((string)$v) !== "")) === 0) continue;
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
        if (strpos($url, "/pubhtml") !== false) {
            $url = preg_replace("#/pubhtml#", "/pub", $url);
        }
        if (strpos($url, "output=csv") === false) {
            $url = $this->add_query_arg_compat($url, "output", "csv");
        }
        if ($gid !== "") {
            $url = $this->add_query_arg_compat($url, "gid", $gid);
        }
        return $url;
    }

    private function add_query_arg_compat($url, $key, $value) {
        $parts = wp_parse_url($url);
        $query = [];
        if (!empty($parts["query"])) parse_str($parts["query"], $query);
        $query[$key] = $value;

        $scheme   = isset($parts["scheme"]) ? $parts["scheme"]."://" : "";
        $host     = $parts["host"] ?? "";
        $port     = isset($parts["port"]) ? ":" . $parts["port"] : "";
        $path     = $parts["path"] ?? "";
        $newQuery = http_build_query($query);
        $frag     = isset($parts["fragment"]) ? "#" . $parts["fragment"] : "";

        return $scheme . $host . $port . $path . ($newQuery ? "?" . $newQuery : "") . $frag;
    }

    private function parse_csv($csv_text) {
        // Manejar saltos de línea Windows/Unix/Mac
        $csv_text = str_replace(["\r\n", "\r"], "\n", $csv_text);
        $lines = explode("\n", $csv_text);
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === "") continue;
            $row = str_getcsv($line);

            // TRIM de columnas vacías al final (evita "columnas fantasma")
            while (!empty($row) && trim(end($row)) === "") {
                array_pop($row);
            }
            $rows[] = $row;
        }
        return $rows;
    }
}

new GSheet_Table_Shortcode();