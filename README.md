# 📋 Manual de Uso - Plugin WP-GSheet Tables

## 📊 Cómo Usar

### 1. **Preparar el Google Sheet**
- Abrir tu Google Sheet
- **Compartir** → **Publicar en la web**
- Copiar la URL de publicación

### 2. **Insertar la Tabla**
Usar el shortcode en cualquier página o post:

```php
[gsheet_table url="TU_URL_AQUI" placeholder="Buscar..."]
```

---

## ⚙️ Parámetros Disponibles

| Parámetro       | Descripción                            | Ejemplo                                                      |
| --------------- | -------------------------------------- | ------------------------------------------------------------ |
| `url`           | **URL del Google Sheet** (obligatorio) | `url="https://docs.google.com/spreadsheets/d/e/ABC123/pubhtml"` |
| `cache_minutes` | Minutos de caché                       | `cache_minutes="10"`                                         |
| `header`        | Mostrar encabezados                    | `header="1"` (sí) / `header="0"` (no)                        |
| `page_size`     | Filas por página                       | `page_size="25"`                                             |
| `search`        | Habilitar búsqueda                     | `search="1"` (sí) / `search="0"` (no)                        |
| `placeholder`   | Texto del buscador                     | `placeholder="Buscar..."`                                    |
| `class`         | Clase CSS personalizada                | `class="mi-tabla"`                                           |

---

## �� Ejemplo Completo

```php
[gsheet_table 
    url="https://docs.google.com/spreadsheets/d/e/F1560N-1vTLGdsadasdasdasdaEPsv9uPO_82cSyjpy4G5-neo4PIU26Fj55hv9Dqn-os-/pubhtml?gid=0&single=true" 
    cache_minutes="15" 
    header="1" 
    page_size="25" 
    search="1" 
    placeholder="Buscar..."
]
```

---

## �� Características

✅ **Búsqueda en tiempo real**  
✅ **Paginación automática**  
✅ **Responsive (móviles y tablets)**  
✅ **Caché para mejor rendimiento**  
✅ **Sin necesidad de API keys**  

---

