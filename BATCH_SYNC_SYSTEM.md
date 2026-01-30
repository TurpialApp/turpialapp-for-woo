# Sistema de Sincronización por Batches

## Descripción

Este sistema permite sincronizar el inventario de Cachicamo a WooCommerce procesando los productos en batches de 500 SKUs cada uno. Si hay múltiples batches, se procesan de forma incremental cada 1 minuto para evitar timeouts.

## Características

- **Procesamiento incremental**: El primer batch se procesa inmediatamente, los siguientes se programan cada 1 minuto
- **Cola persistente**: Todos los batches se guardan en la base de datos antes de procesarse
- **Recuperación de errores**: Si un batch falla, se marca como error pero los demás continúan
- **Estadísticas acumulativas**: Se acumulan las estadísticas de todos los batches procesados
- **Mismo sistema para manual y automático**: Tanto la sincronización manual como el cronjob usan el mismo sistema

## Tabla de Base de Datos

Se crea automáticamente la tabla `wp_cachicamoapp_batch_queue` con la siguiente estructura:

```sql
CREATE TABLE wp_cachicamoapp_batch_queue (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_number int(11) NOT NULL,
  total_batches int(11) NOT NULL,
  sku_list longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  processed_at datetime DEFAULT NULL,
  error_message text DEFAULT NULL,
  PRIMARY KEY (id),
  KEY status (status),
  KEY batch_number (batch_number)
)
```

## Funciones Principales

### `cachicamoapp_update_stock_from_api()`
- Función principal de sincronización
- Recolecta todos los SKUs de WooCommerce
- Divide los SKUs en batches de 500
- Si hay 1 solo batch, lo procesa inmediatamente
- Si hay múltiples batches:
  - Guarda todos en la base de datos
  - Procesa el primero inmediatamente
  - Programa un job recurrente cada 1 minuto para los demás

### `cachicamoapp_process_single_batch()`
- Procesa un único batch de SKUs
- Llama al endpoint `/inventories/batch/sku` de Cachicamo
- Actualiza stock y precios en WooCommerce
- Retorna estadísticas (synced, errors, not_found)

### `cachicamoapp_process_next_batch_job()`
- Se ejecuta cada 1 minuto por el cron de WordPress
- Obtiene el siguiente batch pendiente de la cola
- Lo procesa y marca como completado
- Acumula las estadísticas
- Si no hay más batches, se auto-desactiva

### Funciones auxiliares
- `cachicamoapp_create_batch_queue_table()`: Crea la tabla si no existe
- `cachicamoapp_clear_batch_queue()`: Limpia batches pendientes
- `cachicamoapp_get_next_pending_batch()`: Obtiene el siguiente batch pendiente
- `cachicamoapp_mark_batch_processed()`: Marca un batch como completado/error
- `cachicamoapp_count_pending_batches()`: Cuenta batches pendientes

## Cron Jobs

### `cachicamoapp_update_stock_from_api`
- Se ejecuta según el intervalo configurado (por defecto cada 12 horas)
- Inicia el proceso de sincronización completo

### `cachicamoapp_process_batch_queue`
- Se programa dinámicamente cuando hay batches pendientes
- Se ejecuta cada 1 minuto
- Se auto-desactiva cuando no hay más batches

### `cachicamoapp_one_minute`
- Intervalo personalizado de WordPress Cron
- Configurado para ejecutarse cada 60 segundos

## Flujo de Trabajo

### Sincronización Manual (desde admin)

1. Usuario hace clic en "Force Inventory Sync"
2. Se ejecuta `cachicamoapp_update_stock_from_api()`
3. Si hay múltiples batches:
   - Se guardan todos en la BD
   - Se procesa el batch #1 inmediatamente
   - Se programa el cron `cachicamoapp_process_batch_queue`
   - Cada minuto se procesa el siguiente batch
4. El usuario ve un mensaje con el progreso

### Sincronización Automática (cronjob)

1. El cron `cachicamoapp_update_stock_from_api` se ejecuta cada X minutos (configurado)
2. Mismo proceso que la sincronización manual
3. Los batches se procesan automáticamente cada minuto
4. Al finalizar, se actualiza el timestamp de última sincronización

## Opciones de WordPress

### Estadísticas guardadas
- `cachicamoapp_last_stock_sync`: Timestamp de última sincronización completa
- `cachicamoapp_last_stock_sync_count`: Total de productos sincronizados
- `cachicamoapp_last_stock_sync_errors`: Total de errores
- `cachicamoapp_last_stock_sync_not_found`: Total de SKUs no encontrados

Las estadísticas se acumulan durante el procesamiento de todos los batches.

## Logging

Todos los eventos importantes se registran con `cachicamoapp_log()`:

- Inicio de sincronización
- Creación de cola de batches
- Procesamiento de cada batch
- Errores y excepciones
- Finalización de sincronización

## Ventajas del Sistema

1. **Sin timeouts**: Procesa un batch a la vez, evitando que el servidor se sobrecargue
2. **Recuperación automática**: Si falla un batch, los demás continúan
3. **Trazabilidad**: Toda la información se guarda en la BD
4. **Escalable**: Funciona con tiendas de cualquier tamaño
5. **No bloquea al usuario**: El primer batch se procesa rápido, los demás en background
6. **Consistente**: Manual y automático usan el mismo sistema

## Configuración

El intervalo de sincronización automática se configura en:
**WooCommerce → Settings → Integration → Cachicamo App → Sync Interval (minutes)**

Mínimo: 10 minutos
Por defecto: 720 minutos (12 horas)

## Notas Técnicas

- Los batches de 500 SKUs son un balance entre rendimiento y timeout
- El intervalo de 1 minuto entre batches permite que el servidor respire
- La tabla de cola se limpia automáticamente antes de cada nueva sincronización
- Los hooks de cron se limpian al desactivar el plugin
