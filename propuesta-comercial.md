# Propuesta Comercial — App Móvil para [NOMBRE DEL TORNEO — TBD]

**Preparada para:** Facundo
**Preparada por:** Andrés Campos
**Fecha:** 15 de mayo de 2026
**Validez de la oferta:** 30 días corridos
**Versión:** 1.0

---

## 1. Resumen ejecutivo

Te propongo desarrollar e implementar una **aplicación móvil nativa propia** (iOS + Android) para [NOMBRE DEL TORNEO — TBD], lista para acompañar al torneo desde su lanzamiento en agosto 2026 y crecer junto a él en las próximas temporadas.

La solución incluye:

- App nativa branded con la marca, logo y colores del torneo
- Publicación en App Store (Apple) y Google Play (Android)
- Backend completo de gestión (resultados, equipos, jugadores, tablas, goleadores, fixture)
- Carga inicial de datos del torneo
- Soporte y mantenimiento durante toda la temporada

**Inversión total Año 1: USD 4.360** (Setup USD 2.200 + 12 meses de servicio USD 180/mes)

**Tiempo de implementación: 6 a 8 semanas desde la firma.**

---

## 2. Sobre la solución

La app está construida sobre una plataforma que **ya está en producción** en otro torneo de la misma categoría (referencia: [Entre Redes Padres](https://entreredespadres.com.ar) — Liga Escolar Marianista). Esto significa que no partimos de cero: tenés acceso a un producto **probado, andando con usuarios reales y bugs ya resueltos**.

### Funcionalidades incluidas

| Módulo | Descripción |
|---|---|
| **Partidos** | Jugados y por jugar, con filtros por fecha, zona y equipo |
| **Detalle de partido** | Resultado, formaciones, goleadores, tarjetas |
| **Tabla de posiciones** | Filtrable por zona y temporada |
| **Equipos** | Listado, plantel completo, historial de partidos |
| **Jugadores** | Listado con buscador, perfil individual, estadísticas |
| **Goleadores** | Tabla acumulada por temporada |
| **Arqueros vallas invictas** | Ranking de arqueros (imbatibles) |
| **Notificaciones push** | Avisos a los jugadores sobre próximos partidos, resultados, novedades |
| **Banners / sponsors** | Espacios publicitarios 100% controlados por vos |
| **Caché inteligente** | La app funciona aún con conectividad intermitente |

### Tecnología

- **App**: Flutter (un solo código fuente para iOS y Android → menor costo de mantenimiento a futuro)
- **Backend**: WordPress + SportsPress + plugin custom desarrollado a medida
- **Hospedaje**: Servidor propio del cliente (te quedás con la propiedad de toda la infraestructura)

---

## 3. Análisis del mercado argentino

Antes de presentarte la solución quiero ser **transparente sobre la competencia**. En Argentina existen otras opciones para gestionar torneos amateurs. Las analicé en profundidad para que tomes una decisión informada.

### 3.1 TIMBO (timbo.futbol)

**Qué es**: Plataforma argentina con 180.000 jugadores y más de 300 torneos activos. Tecnología detrás de la Copa Potrero del Kun Agüero.

**Modelo de negocio**: Freemium.

- **Plan gratuito**: TIMBO se reserva el derecho de mostrar **publicidad propia (de TIMBO y de terceros)** dentro de la app, en los espacios de tu torneo. Cita textual de sus T&C: *"TIMBO se reserva el derecho de realizar campañas publicitarias en los espacios de acceso público referidos a aquellos torneos que no adquieran el servicio pago de sponsors o patrocinadores."*
- **Plan pago "Sin Anuncios"**: pago para que TIMBO no inyecte sus anuncios.
- **Servicio "Gestión de Sponsors"**: TIMBO ayuda a vender espacios publicitarios y se queda con comisión.
- **App branded propia** (como la de Copa Potrero del Kun): tier premium, precio no público.

**Pros para vos**:
- Plataforma con tracción y nombre conocido
- Versión free permite arrancar sin inversión inicial

**Contras para vos**:
- La app se llama **"TIMBO"**, no "[NOMBRE DEL TORNEO — TBD]". Los jugadores buscan TIMBO en las stores y ven tu torneo **dentro** de su catálogo, junto a otros 299 torneos competidores.
- En la versión gratuita, los espectadores ven **publicidad de TIMBO y de terceros** dentro de los espacios de tu torneo. Si una marca te quiere auspiciar, vas a tener que disputarle visibilidad a la publicidad inyectada por TIMBO.
- Los datos viven en infraestructura de TIMBO. Si decidieran cerrar o cambiar condiciones, perdés acceso.
- Para tener app branded propia (como Copa Potrero) probablemente haya que pagar un tier premium cuyo precio no es público.

### 3.2 Todo Torneos / iBaires SRL (todotorneos.com.ar)

**Qué es**: Empresa argentina (Buenos Aires) con al menos 8 torneos en su catálogo. Ofrecen apps custom branded por torneo, modelo similar al que te propongo.

**Modelo de negocio**: B2B, precios bajo cotización ("Consultanos").

**Pros para vos**:
- Modelo equivalente al mío (app branded propia)
- Empresa establecida desde 2017 con oficina física

**Contras para vos**:
- **App principal con 3.1 estrellas en Google Play** (sobre 5) — refleja insatisfacción de usuarios
- Stack tecnológico más antiguo (apps nativas separadas Android/iOS, mayor costo de evolución)
- Atención por call center / línea fija — no trato directo con el desarrollador
- Precios no transparentes → cada cotización es una caja negra

### 3.3 Score7.io

Plataforma SaaS internacional, **USD 9/mes plan starter**. Solo web, sin app nativa branded. **No aplica a tu caso** porque buscás una app, no una herramienta web de brackets.

### 3.4 Comparativa

| Eje | TIMBO Free | TIMBO Premium | Todo Torneos | **Esta propuesta** |
|---|---|---|---|---|
| **Costo Año 1** | USD 0 | A consultar | A consultar (estimado USD 2.000-5.000) | **USD 4.360** |
| **App branded propia (marca del torneo)** | No | Solo tier top (Copa Potrero) | Sí | **Sí** |
| **Anuncios de terceros forzados** | Sí | No | No | **No** |
| **Dueño de los sponsors al 100%** | No (TIMBO toma comisión) | Parcial | Sí | **Sí** |
| **Dueño del backend / datos** | No (TIMBO) | No (TIMBO) | No (iBaires) | **Sí (servidor propio)** |
| **Stack moderno (Flutter)** | — | — | No | **Sí** |
| **Trato directo con el desarrollador** | No | No | No | **Sí** |
| **Producto en producción referenciable** | Sí (TIMBO) | Sí | Sí | **Sí (Entre Redes)** |

---

## 4. Mi conclusión y recomendación

Cada opción tiene su lugar según el caso:

- **Si lo único que importa es publicar resultados y tabla, sin invertir un peso**: TIMBO gratis te alcanza. Vas a convivir con publicidad de terceros, pero funciona.

- **Si querés app branded con la marca del torneo, control total de sponsors, y datos propios**: la mejor opción es **esta propuesta** (o equivalente con Todo Torneos, pero con stack más viejo y atención menos directa).

**Mi recomendación para [NOMBRE DEL TORNEO — TBD]**: dado que tenés planeado crecer a más equipos en 2027, conviene **construir marca propia desde el inicio**. Si arrancás con TIMBO Free, los jugadores se acostumbran a "el torneo está en TIMBO". Cuando el año que viene quieras tu app propia, vas a estar cambiando hábito de 88+ usuarios, lo cual es costoso desde el punto de vista de marketing.

**Empezar con app propia desde el Año 1 te posiciona como un torneo serio frente a los participantes, los padres, los sponsors locales, y el ecosistema.**

---

## 5. Plan de trabajo y cronograma

Asumiendo firma de propuesta el **1 de junio de 2026**, el cronograma es el siguiente:

| Semana | Fechas | Hitos |
|---|---|---|
| Semana 1 | 1–7 jun | Discovery, entrega de assets (logo, colores), apertura cuentas Apple/Google |
| Semana 2 | 8–14 jun | Setup backend WordPress + SportsPress + plugin custom |
| Semana 3 | 15–21 jun | Customización app (branding, bundle ID, naming), build inicial |
| Semana 4 | 22–28 jun | Screenshots, fichas de stores, builds de release |
| Semana 5 | 29 jun – 5 jul | Submission iOS + Google Play, carga inicial de datos del torneo |
| Semana 6 | 6–12 jul | Revisión Apple (3-7 días) + ajustes de feedback |
| Semana 7 | 13–19 jul | App publicada, pruebas con cliente, capacitación de carga |
| Semana 8 | 20–26 jul | Hyper-care, ajustes finales, comunicación de lanzamiento |
| **Lanzamiento** | **agosto 2026** | **Inicio del torneo con la app en producción** |

**Buffer real**: el cronograma incluye holgura para imprevistos como rechazo de Apple (lo más frecuente) y retrasos en entrega de assets.

---

## 6. Inversión

### 6.1 Setup (pago único)

| Concepto | USD |
|---|---:|
| Customización de app (branding, naming, bundle IDs, builds) | 800 |
| Setup backend (WordPress + SportsPress + plugin custom + carga inicial) | 700 |
| Publicación en stores (signing, screenshots, fichas, submission, manejo de revisiones) | 500 |
| 30 días de hyper-care post-lanzamiento | 200 |
| **TOTAL SETUP** | **USD 2.200** |

**Forma de pago Setup**:
- 50% (USD 1.100) al firmar la propuesta
- 50% (USD 1.100) al publicarse la app en ambas stores

### 6.2 Servicio mensual

| Concepto | USD/mes |
|---|---:|
| Mantenimiento general | 60 |
| Updates obligatorios de iOS y Android (mínimo 2 al año) | 50 |
| Soporte por canal directo (WhatsApp / email) | 40 |
| Evolución menor (ajustes, pequeños features) | 30 |
| **TOTAL MENSUAL** | **USD 180** |

**Forma de pago Servicio**: mensual, primera cuota al publicarse la app, contrato anual con renovación automática.

### 6.3 Resumen Año 1

| | USD |
|---|---:|
| Setup | 2.200 |
| Mensualidad x 12 meses | 2.160 |
| **TOTAL AÑO 1** | **USD 4.360** |

### 6.4 Año 2 y siguientes

- Sin setup: solo mensualidad de **USD 180/mes** (USD 2.160/año)
- Si el torneo crece a más de 12 equipos: renegociación de mensualidad

### 6.5 Forma de pago

Aceptamos pagos en:

- USD (transferencia internacional o Wise)
- ARS al cambio MEP del día (transferencia bancaria a CBU/CVU)

Facturación: emito factura C / Monotributo argentino por cada cobro.

---

## 7. Qué incluye y qué NO incluye

### Incluye

- Diseño, desarrollo y publicación de la app
- Setup del backend WordPress + plugin custom
- Carga inicial de datos del torneo (equipos, jugadores, fixture)
- Capacitación al equipo del cliente para cargar partidos y resultados
- Mantenimiento y soporte durante 12 meses
- Updates obligatorios de Apple y Google
- Hasta 2 ajustes menores por mes incluidos en mensualidad

### NO incluye (a cargo del cliente)

| Concepto | Costo aproximado | A cargo de |
|---|---|---|
| Hosting WordPress (servidor) | USD 10-25/mes | Cliente, contratado a su nombre |
| Apple Developer Program | USD 99/año | Cliente, cuenta a su nombre |
| Google Play Console | USD 25 (única vez) | Cliente, cuenta a su nombre |
| Dominio web (opcional) | USD 15/año | Cliente |
| Diseño gráfico del logo final | A definir | Cliente (debe entregarlo en vectorial) |

**IMPORTANTE**: las cuentas de Apple Developer y Google Play se abren **a nombre del cliente**, no del desarrollador. Esto garantiza que la app siempre es propiedad del cliente.

### Funcionalidades fuera de scope (cotización aparte si las querés)

- Sistema de inscripción online de jugadores con pago
- Streaming de partidos
- Live scoring en vivo desde la cancha
- Sistema de credenciales con código QR
- Integración con redes sociales (auto-posteo de resultados)
- Apps adicionales para árbitros / delegados

---

## 8. Condiciones comerciales

- **Garantía**: 30 días post-lanzamiento. Cualquier bug que afecte funcionalidad core se corrige sin costo adicional.
- **Soporte**: canal directo de WhatsApp para incidencias, respuesta en horario laboral (lun-vie 9-18h ART).
- **Confidencialidad**: toda la información del torneo, jugadores y datos del cliente se trata bajo confidencialidad. No se comparte con terceros.
- **Propiedad intelectual**: el cliente es propietario de la marca, logo, contenido y datos. El desarrollador retiene los derechos del código base/framework. La instancia personalizada del cliente es de uso exclusivo del cliente.
- **Cancelación**: contrato mensual sin penalidad después del Año 1. Durante el Año 1, en caso de cancelación anticipada, se cobran los meses transcurridos + 2 meses de penalidad.

---

## 9. Sobre Andrés Campos (yo)

- 15+ años de experiencia en desarrollo de software
- Google Developer Expert (GDE) y Microsoft MVP
- Desarrollador de **Entre Redes**, app en producción para Liga Escolar Marianista (referencia comprobable, descargable en ambas stores)
- Stack: Flutter, WordPress, integraciones REST, arquitectura mobile
- Atención directa: trabajás conmigo, no con un call center

**App de referencia**: [Entre Redes Padres en App Store](https://apps.apple.com/ar/app/entre-redes-padres) | [Entre Redes Padres en Google Play](https://play.google.com/store/apps/details?id=com.entreredespadres) — bajala y vela funcionando. Lo que ves ahí es lo que recibe [NOMBRE DEL TORNEO — TBD], con tu marca.

---

## 10. Próximos pasos

Si esta propuesta te parece bien:

1. **Firma de propuesta** (escaneada o digital) → te envío el contrato formal
2. **Pago del primer 50% del setup** (USD 1.100) → inicio formal del proyecto
3. **Reunión kick-off** (1 hora) → te paso el listado completo de assets que necesito y arrancamos

**Tiempo estimado para tener todo en marcha**: 6-8 semanas desde el kick-off. Si firmás antes del 1 de junio, llegamos cómodos al lanzamiento de agosto.

---

## 11. Contacto

**Andrés Campos**
Email: andrescamposaimar@gmail.com
WhatsApp: +54 9 11 5495-4900
LinkedIn: https://www.linkedin.com/in/cesarandrescampos/

---

*Documento preparado el 15 de mayo de 2026. Esta propuesta tiene validez de 30 días. Cualquier consulta o ajuste, me decís y lo conversamos.*
