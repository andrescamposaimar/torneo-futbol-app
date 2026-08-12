/// Decodifica las entidades HTML que devuelve la API de WordPress.
/// Retorna una cadena vacía si el valor es nulo o vacío.
String decodeHtmlEntities(String? text) {
  if (text == null || text.isEmpty) return '';
  return text
      .replaceAll('&amp;', '&')
      .replaceAll('&#8211;', '–')
      .replaceAll('&#8212;', '—')
      .replaceAll('&quot;', '"')
      .replaceAll('&#039;', "'")
      .replaceAll('&#8217;', "'")
      .replaceAll('&lt;', '<')
      .replaceAll('&gt;', '>')
      .replaceAll('&nbsp;', ' ')
      .trim();
}
