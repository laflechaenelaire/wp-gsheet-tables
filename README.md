# Previncasl GSheet Tables

Plugin de WordPress desarrollado para **Grupo Previncasl**.  
Permite mostrar datos de **Google Sheets publicados** en tablas dinámicas con:

- ✅ Búsqueda en tiempo real (front-end)  
- ✅ Paginación configurable  
- ✅ Encabezados opcionales  
- ✅ Estilos responsive  
- ✅ Caché para mejorar rendimiento  
- ✅ Sin necesidad de API key ni licencias externas  

---

## 🚀 Instalación

1. Descargar el repositorio o el archivo ZIP del plugin.  
2. Subir la carpeta `previncasl-gsheet-tables` a `/wp-content/plugins/`.  
3. Activar el plugin desde el panel de administración de WordPress.  

---

## 🔧 Uso

Una vez activado, podés insertar tablas desde Google Sheets con el shortcode:

```text
[gsheet_table url="https://docs.google.com/spreadsheets/d/e/2PACX-XXXX/pubhtml?gid=0&single=true"
              cache_minutes="15"
              header="1"
              page_size="25"
              page_size_opts="10,25,50,100"
              search="1"
              placeholder="Buscar..."
              class="tabla-previncasl"]
