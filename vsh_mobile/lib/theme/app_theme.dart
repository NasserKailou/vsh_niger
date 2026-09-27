import 'package:flutter/material.dart';

/// Jetons de la charte (docs/08_CHARTE_GRAPHIQUE.md), clair et sombre.
class VshColors extends ThemeExtension<VshColors> {
  const VshColors({
    required this.success,
    required this.successSoft,
    required this.info,
    required this.infoSoft,
    required this.warning,
    required this.warningSoft,
    required this.danger,
    required this.dangerSoft,
    required this.neutral,
    required this.neutralSoft,
    required this.textMuted,
  });

  final Color success, successSoft, info, infoSoft, warning, warningSoft, danger, dangerSoft, neutral, neutralSoft, textMuted;

  static const brandGreen = Color(0xFF1E9A3C);
  static const brandOrange = Color(0xFFE8572A);

  static const light = VshColors(
    success: Color(0xFF15803D),
    successSoft: Color(0xFFEFF8F2),
    info: Color(0xFF1D4ED8),
    infoSoft: Color(0xFFE8EEFD),
    warning: Color(0xFFA64B06),
    warningSoft: Color(0xFFFEF1DF),
    danger: Color(0xFFC62828),
    dangerSoft: Color(0xFFFDECEC),
    neutral: Color(0xFF374151),
    neutralSoft: Color(0xFFECEFF1),
    textMuted: Color(0xFF4B5B53),
  );

  static const dark = VshColors(
    success: Color(0xFF4CC775),
    successSoft: Color(0xFF16301F),
    info: Color(0xFF7BA2FF),
    infoSoft: Color(0xFF1A2440),
    warning: Color(0xFFF2A33A),
    warningSoft: Color(0xFF3A2A12),
    danger: Color(0xFFFF7B7B),
    dangerSoft: Color(0xFF3A1A1A),
    neutral: Color(0xFFB8C2CC),
    neutralSoft: Color(0xFF1B2621),
    textMuted: Color(0xFFAEBDB5),
  );

  @override
  VshColors copyWith() => this;

  @override
  VshColors lerp(VshColors? other, double t) => t < 0.5 || other == null ? this : other;
}

extension VshTheme on BuildContext {
  VshColors get vsh => Theme.of(this).extension<VshColors>()!;
}

abstract final class AppTheme {
  static ThemeData light() => _build(
        brightness: Brightness.light,
        primary: const Color(0xFF15803D),
        primarySoft: const Color(0xFFEFF8F2),
        accent: const Color(0xFFB93A12),
        bg: const Color(0xFFF5F8F6),
        surface: const Color(0xFFFFFFFF),
        surface2: const Color(0xFFEEF3F0),
        border: const Color(0xFFDCE4DF),
        text: const Color(0xFF14201A),
        text2: const Color(0xFF4B5B53),
        danger: const Color(0xFFC62828),
        colors: VshColors.light,
      );

  static ThemeData dark() => _build(
        brightness: Brightness.dark,
        primary: const Color(0xFF3FBF66),
        primarySoft: const Color(0xFF16301F),
        accent: const Color(0xFFF2825A),
        bg: const Color(0xFF0D1411),
        surface: const Color(0xFF141D18),
        surface2: const Color(0xFF1B2621),
        border: const Color(0xFF26332C),
        text: const Color(0xFFE6EEE9),
        text2: const Color(0xFFAEBDB5),
        danger: const Color(0xFFFF7B7B),
        colors: VshColors.dark,
      );

  static ThemeData _build({
    required Brightness brightness,
    required Color primary,
    required Color primarySoft,
    required Color accent,
    required Color bg,
    required Color surface,
    required Color surface2,
    required Color border,
    required Color text,
    required Color text2,
    required Color danger,
    required VshColors colors,
  }) {
    final dark = brightness == Brightness.dark;
    final scheme = ColorScheme(
      brightness: brightness,
      primary: primary,
      onPrimary: dark ? const Color(0xFF0D1411) : Colors.white,
      primaryContainer: primarySoft,
      onPrimaryContainer: text,
      // Actions secondaires « tonales » : vert doux (une seule couleur d'action, charte §5).
      secondaryContainer: primarySoft,
      onSecondaryContainer: primary,
      secondary: accent,
      onSecondary: dark ? const Color(0xFF0D1411) : Colors.white,
      error: danger,
      onError: dark ? const Color(0xFF0D1411) : Colors.white,
      surface: surface,
      onSurface: text,
      onSurfaceVariant: text2,
      surfaceContainerLowest: bg,
      surfaceContainerLow: bg,
      surfaceContainer: surface2,
      surfaceContainerHigh: surface2,
      outline: border,
      outlineVariant: border,
    );
    final radius6 = BorderRadius.circular(6);
    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: bg,
      extensions: [colors],
      appBarTheme: AppBarTheme(backgroundColor: surface, foregroundColor: text, elevation: 0, scrolledUnderElevation: 1, centerTitle: false),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10), side: BorderSide(color: border)),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        border: OutlineInputBorder(borderRadius: radius6, borderSide: BorderSide(color: border)),
        enabledBorder: OutlineInputBorder(borderRadius: radius6, borderSide: BorderSide(color: border)),
        focusedBorder: OutlineInputBorder(borderRadius: radius6, borderSide: BorderSide(color: primary, width: 2)),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(minimumSize: const Size(48, 52), shape: RoundedRectangleBorder(borderRadius: radius6)),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48), shape: RoundedRectangleBorder(borderRadius: radius6)),
      ),
      textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(minimumSize: const Size(48, 48))),
      bottomSheetTheme: const BottomSheetThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      ),
      chipTheme: ChipThemeData(shape: const StadiumBorder(), side: BorderSide(color: border)),
      dividerTheme: DividerThemeData(color: border, space: 1),
    );
  }
}
