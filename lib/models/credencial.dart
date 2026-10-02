import 'package:flutter/foundation.dart';

// ---------------------------------------------------------------------------
// GET /entre-redes/v1/credencial/credencial response (design Interfaces
// section; spec capabilities `player-credential` and `photo-approval`).
// ---------------------------------------------------------------------------

/// The server-determined outcome of `GET /credencial/credencial` (design D14:
/// "Eligibility Determination" runs first, then the "Approved Photo Gate").
///
/// [notAPlayer]: the authenticated identity has no matching `sp_player`.
/// [blocked]: `estado` is exactly "Inhabilitado".
/// [noPhoto]: eligible, but no approved (featured-image) photo yet.
/// [active]: eligible + photo approved — [CredencialResponse.credential] is
/// non-null only in this case.
enum CredencialCardState { active, blocked, noPhoto, notAPlayer }

CredencialCardState _cardStateFromWire(String wire) {
  switch (wire) {
    case 'active':
      return CredencialCardState.active;
    case 'blocked':
      return CredencialCardState.blocked;
    case 'no_photo':
      return CredencialCardState.noPhoto;
    case 'not_a_player':
      return CredencialCardState.notAPlayer;
  }
  throw FormatException('Unknown credencial state: "$wire"');
}

String _cardStateToWire(CredencialCardState state) {
  switch (state) {
    case CredencialCardState.active:
      return 'active';
    case CredencialCardState.blocked:
      return 'blocked';
    case CredencialCardState.noPhoto:
      return 'no_photo';
    case CredencialCardState.notAPlayer:
      return 'not_a_player';
  }
}

/// A player's photo upload still under review, or the most recent rejection.
/// Design D11: "rejected is reported only when it is newer than the approved
/// photo" — the server never reports a stale rejection once a newer approved
/// photo exists. No reason is ever included (spec "Rejection Closes the
/// Request Without Publishing").
enum PhotoRequestStatus { pending, rejected }

PhotoRequestStatus _photoRequestStatusFromWire(String wire) {
  switch (wire) {
    case 'pending':
      return PhotoRequestStatus.pending;
    case 'rejected':
      return PhotoRequestStatus.rejected;
  }
  throw FormatException('Unknown photo_request status: "$wire"');
}

String _photoRequestStatusToWire(PhotoRequestStatus status) {
  switch (status) {
    case PhotoRequestStatus.pending:
      return 'pending';
    case PhotoRequestStatus.rejected:
      return 'rejected';
  }
}

@immutable
class CredencialPhotoRequest {
  final int id;
  final PhotoRequestStatus status;
  final String createdAt;

  const CredencialPhotoRequest({
    required this.id,
    required this.status,
    required this.createdAt,
  });

  factory CredencialPhotoRequest.fromJson(Map<String, dynamic> json) {
    return CredencialPhotoRequest(
      id: json['id'] as int,
      status: _photoRequestStatusFromWire(json['status'] as String),
      createdAt: json['created_at'] as String,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'status': _photoRequestStatusToWire(status),
        'created_at': createdAt,
      };

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CredencialPhotoRequest &&
          runtimeType == other.runtimeType &&
          id == other.id &&
          status == other.status &&
          createdAt == other.createdAt;

  @override
  int get hashCode => Object.hash(id, status, createdAt);

  @override
  String toString() =>
      'CredencialPhotoRequest(id: $id, status: $status, createdAt: $createdAt)';
}

/// `team.kind` (design D15) — a player's "team" MAY actually be a waiting or
/// non-enrolled list acting as a team (spec: "Lista de Espera 2026", "Lista
/// de No Inscriptos 2026"). Classified server-side so the client never parses
/// the name itself (design D15: "Works offline").
enum CredencialTeamKind { team, waitingList, notRegistered }

CredencialTeamKind _teamKindFromWire(String wire) {
  switch (wire) {
    case 'team':
      return CredencialTeamKind.team;
    case 'waiting_list':
      return CredencialTeamKind.waitingList;
    case 'not_registered':
      return CredencialTeamKind.notRegistered;
  }
  throw FormatException('Unknown team kind: "$wire"');
}

String _teamKindToWire(CredencialTeamKind kind) {
  switch (kind) {
    case CredencialTeamKind.team:
      return 'team';
    case CredencialTeamKind.waitingList:
      return 'waiting_list';
    case CredencialTeamKind.notRegistered:
      return 'not_registered';
  }
}

@immutable
class CredencialTeam {
  final int id;
  final String name;
  final CredencialTeamKind kind;

  const CredencialTeam({
    required this.id,
    required this.name,
    required this.kind,
  });

  factory CredencialTeam.fromJson(Map<String, dynamic> json) {
    return CredencialTeam(
      id: json['id'] as int,
      name: json['name'] as String,
      kind: _teamKindFromWire(json['kind'] as String),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'kind': _teamKindToWire(kind),
      };

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CredencialTeam &&
          runtimeType == other.runtimeType &&
          id == other.id &&
          name == other.name &&
          kind == other.kind;

  @override
  int get hashCode => Object.hash(id, name, kind);

  @override
  String toString() => 'CredencialTeam(id: $id, name: $name, kind: $kind)';
}

/// Design D5/D4 (rev 9): [id] is the WordPress featured-image attachment id —
/// the photo's real identity. It is what the offline cache is keyed on
/// (`credencial_photo_store.dart`) and what the server rotates the
/// credential id on; [url] is only reachable online and used solely to
/// download the bytes. A missing `id` on the wire is a parse error (design:
/// "a missing id is a parse error") — callers see it surface as the usual
/// GET-parse-failure path, never a silently nullable photo identity.
@immutable
class CredencialPhoto {
  final int id;
  final String url;

  const CredencialPhoto({required this.id, required this.url});

  factory CredencialPhoto.fromJson(Map<String, dynamic> json) {
    return CredencialPhoto(
      id: json['id'] as int,
      url: json['url'] as String,
    );
  }

  Map<String, dynamic> toJson() => {'id': id, 'url': url};

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CredencialPhoto &&
          runtimeType == other.runtimeType &&
          id == other.id &&
          url == other.url;

  @override
  int get hashCode => Object.hash(id, url);

  @override
  String toString() => 'CredencialPhoto(id: $id, url: $url)';
}

/// Echoes `RotatingCode::ALG/STEP/DIGITS` (design D4) so the client never
/// hardcodes the algorithm parameters independently of the server.
@immutable
class CredencialCode {
  final String alg;
  final int step;
  final int digits;

  const CredencialCode({
    required this.alg,
    required this.step,
    required this.digits,
  });

  factory CredencialCode.fromJson(Map<String, dynamic> json) {
    return CredencialCode(
      alg: json['alg'] as String,
      step: json['step'] as int,
      digits: json['digits'] as int,
    );
  }

  Map<String, dynamic> toJson() => {
        'alg': alg,
        'step': step,
        'digits': digits,
      };

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CredencialCode &&
          runtimeType == other.runtimeType &&
          alg == other.alg &&
          step == other.step &&
          digits == other.digits;

  @override
  int get hashCode => Object.hash(alg, step, digits);

  @override
  String toString() => 'CredencialCode(alg: $alg, step: $step, digits: $digits)';
}

/// The full credential payload (design Interfaces section). Present only
/// when [CredencialResponse.state] is [CredencialCardState.active].
///
/// [birthDate] and [caracter] are nullable by design (design D14/spec
/// "Caracter is empty"): an out-of-[18,100] age or a `caracter` outside the
/// 5 valid values renders without failing, simply omitting that field.
@immutable
class Credencial {
  final String id;
  final String issuedAt;
  final String expiresAt;
  final int playerId;
  final String fullName;
  final String dni;
  final String? birthDate;
  final String? caracter;
  final CredencialTeam? team;
  final CredencialPhoto photo;
  final String codeSeed;
  final CredencialCode code;

  const Credencial({
    required this.id,
    required this.issuedAt,
    required this.expiresAt,
    required this.playerId,
    required this.fullName,
    required this.dni,
    this.birthDate,
    this.caracter,
    this.team,
    required this.photo,
    required this.codeSeed,
    required this.code,
  });

  factory Credencial.fromJson(Map<String, dynamic> json) {
    return Credencial(
      id: json['id'] as String,
      issuedAt: json['issued_at'] as String,
      expiresAt: json['expires_at'] as String,
      playerId: json['player_id'] as int,
      fullName: json['full_name'] as String,
      dni: json['dni'] as String,
      birthDate: json['birth_date'] as String?,
      caracter: json['caracter'] as String?,
      team: json['team'] == null
          ? null
          : CredencialTeam.fromJson(json['team'] as Map<String, dynamic>),
      photo: CredencialPhoto.fromJson(json['photo'] as Map<String, dynamic>),
      codeSeed: json['code_seed'] as String,
      code: CredencialCode.fromJson(json['code'] as Map<String, dynamic>),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'issued_at': issuedAt,
        'expires_at': expiresAt,
        'player_id': playerId,
        'full_name': fullName,
        'dni': dni,
        'birth_date': birthDate,
        'caracter': caracter,
        'team': team?.toJson(),
        'photo': photo.toJson(),
        'code_seed': codeSeed,
        'code': code.toJson(),
      };

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is Credencial &&
          runtimeType == other.runtimeType &&
          id == other.id &&
          issuedAt == other.issuedAt &&
          expiresAt == other.expiresAt &&
          playerId == other.playerId &&
          fullName == other.fullName &&
          dni == other.dni &&
          birthDate == other.birthDate &&
          caracter == other.caracter &&
          team == other.team &&
          photo == other.photo &&
          codeSeed == other.codeSeed &&
          code == other.code;

  @override
  int get hashCode => Object.hash(
        id,
        issuedAt,
        expiresAt,
        playerId,
        fullName,
        dni,
        birthDate,
        caracter,
        team,
        photo,
        codeSeed,
        code,
      );

  @override
  String toString() =>
      'Credencial(id: $id, playerId: $playerId, fullName: $fullName)';
}

/// Top-level `GET /credencial/credencial` response body (design Interfaces
/// section). This is exactly the shape [CredencialRepository] caches whole
/// (spec "Offline Cache and Hard Expiry": "cache the full payload locally").
@immutable
class CredencialResponse {
  final CredencialCardState state;
  final CredencialPhotoRequest? photoRequest;
  final Credencial? credential;

  const CredencialResponse({
    required this.state,
    this.photoRequest,
    this.credential,
  });

  factory CredencialResponse.fromJson(Map<String, dynamic> json) {
    return CredencialResponse(
      state: _cardStateFromWire(json['state'] as String),
      photoRequest: json['photo_request'] == null
          ? null
          : CredencialPhotoRequest.fromJson(
              json['photo_request'] as Map<String, dynamic>),
      credential: json['credential'] == null
          ? null
          : Credencial.fromJson(json['credential'] as Map<String, dynamic>),
    );
  }

  Map<String, dynamic> toJson() => {
        'state': _cardStateToWire(state),
        'photo_request': photoRequest?.toJson(),
        'credential': credential?.toJson(),
      };

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CredencialResponse &&
          runtimeType == other.runtimeType &&
          state == other.state &&
          photoRequest == other.photoRequest &&
          credential == other.credential;

  @override
  int get hashCode => Object.hash(state, photoRequest, credential);

  @override
  String toString() =>
      'CredencialResponse(state: $state, photoRequest: $photoRequest, credential: $credential)';
}
