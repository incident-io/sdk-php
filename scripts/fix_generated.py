"""Patch openapi-generator's php-nextgen output after generation.

    python3 scripts/fix_generated.py src openapi.json

Every fix here is an exact-text rewrite of what the generator emits, and every
one must match or the script exits 1, which stops the release. A generator
upgrade that changes the emitted text then shows up as a failure rather than as
a patch that silently stopped applying. That is also why these are rewrites and
not forked templates: openapi-generator turns off mustache's fail-on-missing-key,
so a forked template whose variable was renamed upstream drops a branch without
a word. See `make template-drift`, which fails when the templates these rewrites
anchor on change.

`make generate` clears src/ before generating, so this always runs over fresh
output and does not need to be idempotent.

Report anything fixed here upstream, and delete it when it lands.
"""

import json
import re
import sys
from pathlib import Path

# The generator collapsing, or a pass silently matching almost nothing, would
# otherwise publish. The real figures are roughly double each floor.
FILE_FLOOR = 1000
ENUM_FLOOR = 200
FILTER_PARAM_FLOOR = 20

failures: list[str] = []


def fail(message: str) -> None:
    failures.append(message)


def strip_schema_description(src: Path) -> int:
    """Drop the schema's info.description from every file header.

    The generator pastes the whole API introduction (about 9KB: auth, rate
    limits, errors) into the docblock of every file, which is about a third of
    the package's size and says nothing about the class it sits on. The
    appDescription option does not override it.
    """
    header = re.compile(
        r"(/\*\*\n \* incident\.io\n \*\n) \* [^\n]*\n \*\n( \* The version of the OpenAPI document)"
    )
    patched = 0
    for path in src.rglob("*.php"):
        text = path.read_text()
        new, n = header.subn(r"\1\2", text, count=1)
        if n != 1:
            fail(f"{path}: schema description header not found")
            continue
        path.write_text(new)
        patched += 1
    return patched


# One block per enum-typed property in a model, in three shapes. All three are
# removed; the constants and get<Prop>AllowableValues() stay for callers.
ENUM_CHECKS = [
    # listInvalidProperties(): a scalar enum property.
    re.compile(
        r"        \$allowedValues = self::get\w+AllowableValues\(\);\n"
        r"        if \(!is_null\(\$this->container\['\w+'\]\) && !in_array\(\$this->container\['\w+'\], \$allowedValues, true\)\) \{\n"
        r"            \$invalidProperties\[\] = sprintf\(\n"
        r"(?:                [^\n]*\n)+?"
        r"            \);\n"
        r"        \}\n\n"
    ),
    # set<Prop>(): a scalar enum property.
    re.compile(
        r"        \$allowedValues = self::get\w+AllowableValues\(\);\n"
        r"        if \(!in_array\(\$\w+, \$allowedValues, true\)\) \{\n"
        r"            throw new InvalidArgumentException\(\n"
        r"(?:                [^\n]*\n)+?"
        r"            \);\n"
        r"        \}\n"
    ),
    # set<Prop>(): an array-of-enum property.
    re.compile(
        r"        \$allowedValues = self::get\w+AllowableValues\(\);\n"
        r"        if \(array_diff\(\$\w+, \$allowedValues\)\) \{\n"
        r"            throw new InvalidArgumentException\(\n"
        r"(?:                [^\n]*\n)+?"
        r"            \);\n"
        r"        \}\n"
    ),
]


def open_enums(models: Path) -> int:
    """Accept enum values the SDK does not know about, and keep them verbatim.

    The API adds enum values as a backwards-compatible change (the schema's
    Compatibility section says so). The generator's setters throw
    InvalidArgumentException on any value not in the list, and deserialization
    goes through the setters, so without this every installed client would
    fail to read a response the moment the server sent a new value.

    Not the generator's enumUnknownDefaultCase option: that replaces an unknown
    value with the string 'unknown_default_open_api', which loses the value on
    the way in and, because it also applies to setters, would send that string
    to the API if a caller set a value newer than their SDK.
    """
    declared = 0
    removed = 0
    for path in models.glob("*.php"):
        text = path.read_text()
        declared += len(re.findall(r"public static function get\w+AllowableValues\(\)", text))
        for pattern in ENUM_CHECKS:
            text, n = pattern.subn("", text)
            removed += n
        if "$allowedValues" in text:
            fail(f"{path}: an enum check was left in place; ENUM_CHECKS no longer matches every shape")
        path.write_text(text)
    if declared < ENUM_FLOOR:
        fail(f"only {declared} enum properties found (floor {ENUM_FLOOR})")
    return removed


FILTER_ANCHOR = "        // since \\GuzzleHttp\\Psr7\\Query::build fails with nested arrays\n"

FILTER_PATCH = """\
        // Patched by scripts/fix_generated.py. The API's filters are objects
        // whose values are lists, decoded as status[one_of]=a&status[one_of]=b.
        // The flattening below gets both styles wrong: form turns it into
        // 0=a&1=b with the parameter name dropped, and deepObject into
        // status[one_of][0]=a&status[one_of][1]=b, of which the server keeps
        // one value. Keep the name as a prefix, nest each key in brackets, and
        // leave list values as lists so buildQuery repeats the key once per
        // value.
        if ($openApiType === 'object' && ($style === 'deepObject' || ($style === 'form' && $explode)) && is_array($value)) {
            // Passed to itself rather than captured by reference, which would
            // make a reference cycle that only the cycle collector frees.
            $bracket = static function (array $arr, string $prefix, callable $bracket): array {
                $result = [];
                foreach ($arr as $k => $v) {
                    $key = "{$prefix}[{$k}]";
                    if (is_array($v) && !array_is_list($v)) {
                        $result += $bracket($v, $key, $bracket);
                    } else {
                        $result[$key] = $v;
                    }
                }
                return $result;
            };
            return $bracket($value, $paramName, $bracket);
        }

"""


def fix_filter_params(serializer: Path, spec: dict) -> int:
    """Encode object-typed query parameters the way the API decodes them."""
    text = serializer.read_text()
    if text.count(FILTER_ANCHOR) != 1:
        fail(f"{serializer}: toQueryValue flattening anchor not found exactly once")
        return 0
    serializer.write_text(text.replace(FILTER_ANCHOR, FILTER_PATCH + FILTER_ANCHOR))

    # The patch only helps if the generator still calls toQueryValue with
    # 'object' and either deepObject or exploded form style for these
    # parameters. Count the call sites against the schema, so a change in
    # either shows up here. OpenAPI defaults explode to true for form only.
    declared = 0
    for ops in spec["paths"].values():
        for op in ops.values():
            if not isinstance(op, dict):
                continue
            for param in op.get("parameters", []):
                if param.get("in") != "query" or param.get("schema", {}).get("type") != "object":
                    continue
                style = param.get("style", "form")
                if style == "deepObject" or (style == "form" and param.get("explode", True)):
                    declared += 1
    emitted = 0
    for path in serializer.parent.joinpath("Api").glob("*.php"):
        emitted += len(
            re.findall(
                r"'object', // openApiType\n\s+(?:'deepObject', // style\n\s+(?:true|false)|'form', // style\n\s+true), // explode",
                path.read_text(),
            )
        )
    if emitted != declared:
        fail(f"{declared} object query parameters in the schema but {emitted} object call sites generated")
    if declared < FILTER_PARAM_FLOOR:
        fail(f"only {declared} object query parameters found (floor {FILTER_PARAM_FLOOR})")
    return emitted


USER_AGENT_DOC = ' set to "OpenAPI-Generator/{version}/PHP" by default'
CONSTRUCTOR = "    public function __construct()\n    {\n        $this->tempFolderPath = sys_get_temp_dir();\n    }\n"

CONSTRUCTOR_PATCH = """\
    public function __construct()
    {
        $this->tempFolderPath = sys_get_temp_dir();
        $this->userAgent = 'incident-io-sdk-php/' . self::sdkVersion();
    }

    /**
     * The installed version of this package, as Composer recorded it.
     *
     * Patched in by scripts/fix_generated.py. There is no version constant to
     * keep in step: Packagist takes the version from the git tag, so Composer's
     * runtime record is the only place it exists.
     */
    private static function sdkVersion(): string
    {
        if (class_exists(\\Composer\\InstalledVersions::class)) {
            try {
                $version = \\Composer\\InstalledVersions::getPrettyVersion('incident-io/sdk-php');
                if ($version !== null) {
                    return ltrim($version, 'v');
                }
            } catch (\\OutOfBoundsException $e) {
                // Not installed through Composer, e.g. a path checkout.
            }
        }
        return 'dev';
    }
"""


def fix_user_agent(configuration: Path) -> None:
    """Identify as incident-io-sdk-php/<version>, like sdk-go and sdk-rust.

    The Makefile's httpUserAgent option sets the property's default to
    incident-io-sdk-php/dev; this adds the installed version at runtime.
    """
    text = configuration.read_text()
    for anchor in (USER_AGENT_DOC, CONSTRUCTOR):
        if text.count(anchor) != 1:
            fail(f"{configuration}: user agent anchor not found exactly once: {anchor.strip()[:60]}")
            return
    text = text.replace(USER_AGENT_DOC, ' set to "incident-io-sdk-php/{version}" by default')
    text = text.replace(CONSTRUCTOR, CONSTRUCTOR_PATCH)
    configuration.write_text(text)


def check_deprecations(api: Path, spec: dict) -> int:
    """Assert every deprecated operation carries @deprecated.

    The generator does emit these, on all five methods of an operation (the
    call, WithHttpInfo, Async, AsyncWithHttpInfo and the Request builder).
    Nothing patches them; this only checks the count still lines up.
    """
    deprecated = sum(
        1
        for ops in spec["paths"].values()
        for op in ops.values()
        if isinstance(op, dict) and op.get("deprecated")
    )
    emitted = sum(path.read_text().count("     * @deprecated\n") for path in api.glob("*.php"))
    if emitted != deprecated * 5:
        fail(f"{deprecated} deprecated operations in the schema, so expected {deprecated * 5} @deprecated tags, found {emitted}")
    return deprecated


def main() -> None:
    src = Path(sys.argv[1])
    spec = json.loads(Path(sys.argv[2]).read_text())

    files = strip_schema_description(src)
    if files < FILE_FLOOR:
        fail(f"only {files} generated files (floor {FILE_FLOOR})")
    enums = open_enums(src / "Model")
    filters = fix_filter_params(src / "ObjectSerializer.php", spec)
    fix_user_agent(src / "Configuration.php")
    deprecated = check_deprecations(src / "Api", spec)

    print(f"files: {files}, enum checks removed: {enums}, filter params: {filters}, deprecated operations: {deprecated}")
    if failures:
        for message in failures:
            print(f"error: {message}", file=sys.stderr)
        print("scripts/fix_generated.py: not safe to publish", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
