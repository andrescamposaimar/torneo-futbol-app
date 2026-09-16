# Custom R8 rules for this module. The Flutter Gradle Plugin already wires
# proguard-android-optimize.txt and its own flutter_proguard_rules.pro; this
# file only carries what those two do not cover.
#
# Keep this file minimal. Every rule here is code R8 is forbidden to shrink or
# rename, so a preventive rule is not free: it is the obfuscation score Google
# Play is measuring. Add a rule when a release build actually breaks, and note
# what broke.

# --- flutter_local_notifications ---
# The plugin persists notification state through GSON, which resolves types by
# reflection at runtime. None of the notification plugins in this project ship
# consumer rules, and the plugin publishes these only in its example app, so
# they have to live here. Without them R8 strips the generic signatures GSON
# needs and notification payloads deserialize as null.
-keepattributes Signature
-keepattributes *Annotation*
-dontwarn sun.misc.**
-keep class * extends com.google.gson.TypeAdapter
-keep class * implements com.google.gson.TypeAdapterFactory
-keep class * implements com.google.gson.JsonSerializer
-keep class * implements com.google.gson.JsonDeserializer
-keepclassmembers,allowobfuscation class * {
  @com.google.gson.annotations.SerializedName <fields>;
}
-keep,allowobfuscation,allowshrinking class com.google.gson.reflect.TypeToken
-keep,allowobfuscation,allowshrinking class * extends com.google.gson.reflect.TypeToken
