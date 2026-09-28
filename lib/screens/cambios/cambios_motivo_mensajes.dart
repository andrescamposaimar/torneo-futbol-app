/// Maps a [CambiosDictamenMotivo.codigo] (a stable rule id from the backend's
/// `Dictamen\Reglas\*` classes) to a parent-facing Spanish sentence.
///
/// The backend's own `mensaje` field is committee-facing copy (per
/// `Dictamen\Motivo`'s own docblock, "UI copy read verbatim by the
/// subcomisión") and at least one rule (`EntranteNoBloqueado`) embeds an
/// internal shorthand ("ver CC5b") that must never reach a parent's screen —
/// so this app renders ONLY the mapping below, never the server's `mensaje`.
///
/// Every code below is enumerated from
/// `wordpress_plugins/entre-redes-cambios/src/Dictamen/Reglas/*.php`'s
/// `private const CODE` constants. An unrecognized code (future rule, or a
/// server/client version skew) falls back to a generic, still-honest
/// message rather than a blank or a raw code on screen.
String cambiosMotivoMensaje(String codigo) {
  switch (codigo) {
    case 'entrante_ocupa_otra_plaza_vigente':
      return 'El jugador elegido ya está ocupando otra plaza en esta temporada.';
    case 'entrante_es_el_saliente':
      return 'El jugador elegido ya está en esta plaza: no puede reemplazarse a sí mismo.';
    case 'entrante_bloqueado_por_cierre_truncado':
      return 'El jugador elegido no puede entrar todavía: dejó otra plaza sin completar el proceso de cambio.';
    case 'prioridad_de_padres_no_respetada':
      return 'Hay padres disponibles para esta plaza y el reglamento les da prioridad sobre el jugador elegido.';
    case 'regreso_antes_del_minimo':
      return 'El titular todavía no puede volver: falta esperar más fechas.';
    case 'plaza_cerrada':
      return 'Esta plaza fue cerrada por la comisión y ya no admite cambios.';
    case 'puntaje_excede_techo':
      return 'El puntaje del jugador elegido supera el techo permitido para esta plaza.';
    case 'entrante_puntaje_indeterminado':
      return 'No pudimos calcular el puntaje del jugador elegido, así que no se pudo evaluar el pedido.';
    case 'fuera_de_plazo':
      return 'El pedido se hizo fuera del plazo permitido (revisá los días de cierre del cambio o del regreso).';
    case 'plaza_sin_ocupacion_vigente':
      return 'Esta plaza no tiene a nadie ocupándola actualmente, así que no hay de quién pedir el regreso.';
    default:
      return 'El pedido no cumple con una de las reglas del reglamento.';
  }
}
