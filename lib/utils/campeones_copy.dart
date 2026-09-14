/// Pluralised label for the player-detail titles panel header, following the
/// `_seccionHeader` trailing-count convention `_buildTemporadas()` already
/// uses ('1 temporada' / 'N temporadas').
String etiquetaTitulos(int total) => total == 1 ? '1 título' : '$total títulos';
