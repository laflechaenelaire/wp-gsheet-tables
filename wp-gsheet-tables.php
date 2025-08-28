<?php
/**
 * Plugin Name: WP-GSheet Tables
 * Plugin URI: https://github.com/previncasl/wp-gsheet-tables
 * Description: Muestra datos de Google Sheets en tablas con búsqueda (por columna o todas), y paginación.
 * Version: 1.1.0
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Author: Santiago M. González
 * Author URI: https://github.com/previncasl
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-gsheet-tables
 * Domain Path: /languages
 * Network: false
 */
if (!defined('ABSPATH')) exit;

// Verificar versión mínima de PHP
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>PrevincaSL GSheet Tables</strong> requiere PHP 7.4 o superior. Tu versión actual es ' . PHP_VERSION . '.';
        echo '</p></div>';
    });
    return;
}

// Verificar versión mínima de WordPress
if (version_compare(get_bloginfo('version'), '5.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>PrevincaSL GSheet Tables</strong> requiere WordPress 5.0 o superior.';
        echo '</p></div>';
    });
    return;
}

class GSheet_Table_Shortcode {
    const TRANSIENT_PREFIX = 'gst_cache_';

    public function __construct() {
        add_shortcode('gsheet_table', [$this, 'shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets() {
        $css = '
        .gst-root{--gst-gap:.75rem}
        .gst-wrap{overflow-x:auto;max-width:100%}
        .gst-controls{display:flex;gap:var(--gst-gap);align-items:stretch;flex-wrap:wrap;margin:.5rem 0 .75rem}
        .gst-search{flex:1;min-width:200px;display:flex;gap:.5rem;flex-direction:row}
        .gst-search input{flex:1;width:100%;padding:.5rem;border:1px solid #ddd;border-radius:6px;font-size:16px}
        .gst-col-one{min-width:120px;max-width:180px;padding:.45rem;border:1px solid #ddd;border-radius:6px;flex-shrink:0;font-size:14px}
        .gst-page-size{min-width:120px;padding:.45rem;border:1px solid #ddd;border-radius:6px;flex-shrink:0;font-size:14px}
        table.gst{border-collapse:collapse;width:100%;min-width:600px}
        .gst th,.gst td{border:1px solid #e5e5e5;padding:.5rem;vertical-align:top;word-wrap:break-word;max-width:200px}
        .gst thead th{background:#f7f7f7;font-weight:600;font-size:14px}
        .gst tbody td{font-size:14px}
        .gst-pagination{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:.75rem 0;justify-content:center}
        .gst-pagination button{padding:.35rem .6rem;border:1px solid #ddd;background:#fff;border-radius:6px;cursor:pointer;font-size:14px;min-width:80px}
        .gst-pagination button[disabled]{opacity:.5;cursor:not-allowed}
        .gst-page-indicator{font-size:14px;text-align:center;flex:1;min-width:200px}
        
        /* Responsive para tablets */
        @media (max-width: 768px) {
            .gst-controls{flex-direction:column;gap:.5rem}
            .gst-search{flex-direction:column;min-width:100%}
            .gst-search input{min-height:44px}
            .gst-col-one,.gst-page-size{min-width:100%;max-width:none}
            .gst-pagination{flex-direction:column;gap:.25rem}
            .gst-pagination button{width:100%;min-height:44px}
            .gst-page-indicator{order:-1;margin-bottom:.5rem}
            table.gst{min-width:500px}
            .gst th,.gst td{padding:.35rem;font-size:13px}
        }
        
        /* Responsive para móviles */
        @media (max-width: 480px) {
            .gst-root{--gst-gap:.5rem}
            .gst-controls{margin:.25rem 0 .5rem}
            .gst-search input{font-size:16px;padding:.6rem}
            .gst-col-one,.gst-page-size{font-size:16px;padding:.6rem}
            .gst-pagination button{font-size:16px;padding:.5rem .8rem}
            .gst-page-indicator{font-size:14px}
            table.gst{min-width:400px}
            .gst th,.gst td{padding:.25rem;font-size:12px;max-width:150px}
            .gst thead th{font-size:12px}
        }
        
        /* Responsive para móviles muy pequeños */
        @media (max-width: 360px) {
            table.gst{min-width:300px}
            .gst th,.gst td{padding:.2rem;font-size:11px;max-width:120px}
            .gst thead th{font-size:11px}
            .gst-pagination button{font-size:14px;padding:.4rem .6rem}
        }
        ';
        wp_register_style('gst-inline', false);
        wp_enqueue_style('gst-inline');
        wp_add_inline_style('gst-inline', $css);

        $js = '
        (function(){
          function normalize(s){ return (s||"").toString().toLowerCase(); }

          function setup(tableId){
            var root = document.querySelector("[data-gst-id=\'"+tableId+"\']");
            if(!root) return;
            var tbody       = root.querySelector("tbody");
            var rows        = Array.from(tbody.querySelectorAll("tr"));
            var searchInput = root.querySelector(".gst-search input");
            var colSelect   = root.querySelector(".gst-col-one");
            var pageSizeSel = root.querySelector(".gst-page-size");
            var prevBtn     = root.querySelector(".gst-prev");
            var nextBtn     = root.querySelector(".gst-next");
            var indicator   = root.querySelector(".gst-page-indicator");

            var pageSize = parseInt(pageSizeSel.value || "10", 10);
            var page = 1;
            var filtered = rows.slice();

            function apply(){
              var total = filtered.length;
              var totalPages = Math.max(1, Math.ceil(total / pageSize));
              if(page > totalPages) page = totalPages;
              if(page < 1) page = 1;

              rows.forEach(function(tr){ tr.style.display = "none"; });
              var start = (page-1)*pageSize, end = start + pageSize;
              filtered.slice(start, end).forEach(function(tr){ tr.style.display = ""; });

              prevBtn.disabled = page <= 1;
              nextBtn.disabled = page >= totalPages;
              indicator.textContent = total ? ("Página "+page+" de "+totalPages+" — "+total+" filas") : "Sin resultados";
            }

            function doFilter(){
              var q = normalize(searchInput.value);
              var colIdx = parseInt(colSelect.value, 10); // -1 = todas
              if(!q){
                filtered = rows.slice();
              } else {
                filtered = rows.filter(function(tr){
                  var cells = Array.from(tr.cells);
                  if(colIdx >= 0){
                    // filtrar solo esa columna si existe
                    return cells[colIdx] && normalize(cells[colIdx].textContent).includes(q);
                  } else {
                    // todas las columnas
                    for(var i=0;i<cells.length;i++){
                      if(normalize(cells[i].textContent).includes(q)) return true;
                    }
                    return false;
                  }
                });
              }
              page = 1; apply();
            }

            searchInput.addEventListener("input", doFilter);
            colSelect.addEventListener("change", doFilter);
            pageSizeSel.addEventListener("change", function(){
              pageSize = parseInt(pageSizeSel.value||"10",10);
              page = 1; apply();
            });
            prevBtn.addEventListener("click", function(){ page--; apply(); });
            nextBtn.addEventListener("click", function(){ page++; apply(); });

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
            'search'         => '1',
            'page_size'      => '10',
            'page_size_opts' => '10,25,50,100',
            'placeholder'    => 'Buscar...',
        ], $atts, 'gsheet_table');

        $url = trim($atts['url']);
        if ($url === '') return '<em>GSheet Table: falta el parámetro <code>url</code>.</em>';

        $csv_url = $this->normalize_to_csv_url($url, $atts['gid']);

        $cache_minutes = max(0, intval($atts['cache_minutes']));
        $cache_key = self::TRANSIENT_PREFIX . md5($csv_url . "|" . $atts['header'] . "|" . $atts['limit'] . "|" . $atts['page_size'] . "|" . $atts['page_size_opts'] . "|" . $atts['search']);

        if ($cache_minutes > 0) {
            $cached = get_transient($cache_key);
            if ($cached !== false) return $cached;
        }

        $response = wp_remote_get($csv_url, ['timeout'=>15,'headers'=>['Accept'=>'text/csv']]);
        if (is_wp_error($response)) return $this->render_error('No se pudo obtener el CSV: ' . esc_html($response->get_error_message()));
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) return $this->render_error('Respuesta inválida del servidor (' . intval($status) . ').');
        $body = wp_remote_retrieve_body($response);
        if ($body === '' || $body === null) return $this->render_error('CSV vacío.');

        $rows = $this->parse_csv($body);
        if (empty($rows)) return $this->render_error('No se encontraron filas en el CSV.');

        $use_header = ($atts['header'] === '1');

        // LIMIT antes de separar header
        $limit = max(0, intval($atts['limit']));
        if ($limit > 0) {
            if ($use_header && count($rows) > 1) $rows = array_merge([ $rows[0] ], array_slice($rows, 1, $limit));
            else $rows = array_slice($rows, 0, $limit);
        }

        // Labels para el select de columna
        $header_labels = [];
        if ($use_header) {
            $header_labels = $rows[0];
        } else {
            $sample = $rows[0];
            $cols = is_array($sample) ? count($sample) : 0;
            for ($i=0; $i<$cols; $i++) $header_labels[] = 'Col ' . ($i+1);
        }

        // Procesar filas para eliminar columnas sin nombre de encabezado
        $rows = $this->remove_unnamed_columns($rows, $header_labels);
        
        // Usar los labels actualizados si están disponibles
        $final_header_labels = isset($GLOBALS['gst_header_labels']) ? $GLOBALS['gst_header_labels'] : $header_labels;

        $table_class = 'gst' . ($atts['class'] ? ' ' . sanitize_html_class($atts['class']) : '');
        $table_id = 'gst_' . substr(md5(uniqid('', true)), 0, 8);

        ob_start();
        echo '<div class="gst-root" data-gst-id="'.esc_attr($table_id).'">';

        // Controles: búsqueda + select de columna + page size
        echo '<div class="gst-controls">';
        if ($atts['search'] === '1') {
            echo '<div class="gst-search">';
            echo '<input type="search" placeholder="'.esc_attr($atts['placeholder']).'">';
            echo '<select class="gst-col-one">';
            echo '<option value="-1" selected>Todas las columnas</option>';
            foreach ($final_header_labels as $idx => $lbl) {
                $txt = trim($lbl) === "" ? ("Col ".($idx+1)) : $lbl;
                echo '<option value="'.esc_attr($idx).'">'.esc_html($txt).'</option>';
            }
            echo '</select>';
            echo '</div>';
        } else {
            echo '<div style="flex:1"></div>';
        }

        $opts = array_filter(array_map('trim', explode(',', $atts['page_size_opts'])));
        $default_ps = intval($atts['page_size']) ?: 10;
        if (!in_array((string)$default_ps, $opts, true)) { array_unshift($opts, (string)$default_ps); $opts = array_unique($opts); }
        echo '<select class="gst-page-size">';
        foreach ($opts as $o) {
            $sel = ((string)$default_ps === trim($o)) ? ' selected' : '';
            echo '<option value="'.esc_attr(trim($o)).'"'.$sel.'>'.esc_html(trim($o)).' por página</option>';
        }
        echo '</select>';
        echo '</div>'; // .gst-controls

        // Tabla
        echo '<div class="gst-wrap"><table class="'.esc_attr($table_class).'">';
        $render_col_count = 0;
        if ($use_header) {
            $header_row = array_shift($rows);
            // Asegurar que usamos los labels finales y contamos columnas válidas
            $safe_header = isset($final_header_labels) && is_array($final_header_labels) && count($final_header_labels) > 0
                ? $final_header_labels
                : $header_row;
            $render_col_count = count($safe_header);
            echo '<thead><tr>';
            foreach ($safe_header as $cell) echo '<th>'.esc_html($cell).'</th>';
            echo '</tr></thead>';
        }
        echo '<tbody>';
        foreach ($rows as $r) {
            if (!is_array($r) || count(array_filter($r, fn($v)=> trim((string)$v) !== "")) === 0) continue;
            echo '<tr>';
            // Limitar las celdas renderizadas al número de columnas del header
            $max_i = $render_col_count > 0 ? $render_col_count : count($r);
            for ($i = 0; $i < $max_i; $i++) {
                $cell = $r[$i] ?? '';
                echo '<td>'.esc_html($cell).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // Paginación
        echo '<div class="gst-pagination">';
        echo '<button type="button" class="gst-prev">&laquo; Anterior</button>';
        echo '<button type="button" class="gst-next">Siguiente &raquo;</button>';
        echo '<span class="gst-page-indicator" aria-live="polite" style="margin-left:.5rem"></span>';
        echo '</div>';

        echo '</div>'; // .gst-root
        $html = ob_get_clean();

        if ($cache_minutes > 0) set_transient($cache_key, $html, $cache_minutes * MINUTE_IN_SECONDS);
        return $html;
    }

    private function remove_unnamed_columns($rows, $header_labels) {
        if (empty($rows) || empty($header_labels)) return $rows;
        
        // Encontrar columnas que no tienen nombre de encabezado válido
        $unnamed_columns = [];
        
        for ($col = 0; $col < count($header_labels); $col++) {
            $header_name = isset($header_labels[$col]) ? trim($header_labels[$col]) : '';
            // Considerar vacío si el encabezado está vacío o solo tiene espacios
            if ($header_name === '' || $header_name === null) {
                $unnamed_columns[] = $col;
            }
        }
        
        // Si no hay columnas sin nombre, devolver las filas originales
        if (empty($unnamed_columns)) return $rows;
        
        // Remover las columnas sin nombre
        $result = [];
        foreach ($rows as $row) {
            $new_row = [];
            for ($col = 0; $col < count($header_labels); $col++) {
                if (!in_array($col, $unnamed_columns)) {
                    $new_row[] = $row[$col] ?? '';
                }
            }
            $result[] = $new_row;
        }
        
        // También actualizar los labels de encabezado
        $new_header_labels = [];
        for ($col = 0; $col < count($header_labels); $col++) {
            if (!in_array($col, $unnamed_columns)) {
                $new_header_labels[] = $header_labels[$col];
            }
        }
        
        // Actualizar los labels globalmente
        $GLOBALS['gst_header_labels'] = $new_header_labels;
        
        return $result;
    }

    private function remove_empty_columns($rows) {
        if (empty($rows)) return $rows;
        
        // Normalizar el número de columnas basado en la fila con más columnas
        $max_cols = 0;
        foreach ($rows as $row) {
            $max_cols = max($max_cols, count($row));
        }
        
        // Encontrar columnas que están completamente vacías
        $empty_columns = [];
        
        for ($col = 0; $col < $max_cols; $col++) {
            $is_empty = true;
            foreach ($rows as $row) {
                $cell_value = isset($row[$col]) ? trim($row[$col]) : '';
                if ($cell_value !== '') {
                    $is_empty = false;
                    break;
                }
            }
            if ($is_empty) {
                $empty_columns[] = $col;
            }
        }
        
        // Si no hay columnas vacías, devolver las filas originales
        if (empty($empty_columns)) return $rows;
        
        // Remover las columnas vacías
        $result = [];
        foreach ($rows as $row) {
            $new_row = [];
            for ($col = 0; $col < $max_cols; $col++) {
                if (!in_array($col, $empty_columns)) {
                    $new_row[] = $row[$col] ?? '';
                }
            }
            $result[] = $new_row;
        }
        
        return $result;
    }

    private function render_error($msg) {
        return '<div class="gst-error" style="border:1px solid #f2d7d5;background:#fdecea;padding:.5rem 1rem;color:#6a1a1a;">' . esc_html($msg) . '</div>';
    }

    private function normalize_to_csv_url($url, $gid) {
        if (strpos($url, '/pubhtml') !== false) $url = preg_replace('#/pubhtml#', '/pub', $url);
        if (strpos($url, 'output=csv') === false) $url = $this->add_query_arg_compat($url, 'output', 'csv');
        if ($gid !== '') $url = $this->add_query_arg_compat($url, 'gid', $gid);
        return $url;
    }

    private function add_query_arg_compat($url, $key, $value) {
        $parts = wp_parse_url($url);
        $query = [];
        if (!empty($parts['query'])) parse_str($parts['query'], $query);
        $query[$key] = $value;

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path   = $parts['path'] ?? '';
        $newQ   = http_build_query($query);
        $frag   = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        return $scheme.$host.$port.$path.($newQ ? '?'.$newQ : '').$frag;
    }

    private function parse_csv($csv_text) {
        $csv_text = str_replace(["\r\n","\r"], "\n", $csv_text);
        $lines = explode("\n", $csv_text);
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === "") continue;
            
            // Limpiar caracteres problemáticos que pueden causar problemas en el parsing
            $line = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line);
            
            $row = str_getcsv($line);
            if (empty($row)) continue;
            
            // Limpiar cada celda de caracteres problemáticos
            $row = array_map(function($cell) {
                $cell = trim($cell);
                // Remover caracteres de control y caracteres problemáticos
                $cell = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cell);
                return $cell;
            }, $row);
            
            // Quitar columnas vacías al final
            while (!empty($row) && trim(end($row)) === "") array_pop($row);
            
            // Solo agregar filas que tengan al menos una celda con contenido
            if (!empty($row) && count(array_filter($row, function($cell) { return trim($cell) !== ''; })) > 0) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
new GSheet_Table_Shortcode();
