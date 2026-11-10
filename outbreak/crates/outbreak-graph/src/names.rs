//! What one PHP file declares and names: the classes, interfaces, traits,
//! enums and functions it declares, every name it uses resolved against its
//! namespace and imports, and whether it holds a node the graph cannot
//! place: a call by a name held as it runs, a class built from a variable, a
//! reflection, a container read by a non-literal key, an include of a built
//! path, or a magic method that answers any name.

use std::collections::{BTreeMap, BTreeSet};

use crate::lexer::{Token, tokens};

/// What one file declares and names.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct FileNames {
    /// Each class-like and function the file declares, fully qualified, lower-cased.
    pub declares: BTreeSet<String>,
    /// Each name the file uses, fully qualified, lower-cased, as a class-like or a function.
    pub names: BTreeSet<String>,
    /// Why the file holds a node the graph cannot place; none where it holds none.
    pub unplaced: Option<&'static str>,
}

const DECLARING: [&str; 4] = ["class", "interface", "trait", "enum"];

const DYNAMIC_CALLS: [&str; 6] = [
    "call_user_func",
    "call_user_func_array",
    "forward_static_call",
    "forward_static_call_array",
    "constant",
    "class_exists",
];

const REFLECTIONS: [&str; 5] = [
    "reflectionclass",
    "reflectionmethod",
    "reflectionfunction",
    "reflectionproperty",
    "reflectionobject",
];

const INCLUDES: [&str; 4] = ["include", "include_once", "require", "require_once"];

const MAGIC: [&str; 4] = ["__call", "__callstatic", "__get", "__set"];

const RESOLVERS: [&str; 5] = ["get", "make", "resolve", "build", "app"];

/// What a constant is named by, apart from a class-like or a function: its last segment after this.
const CONSTANT: &str = "const ";

const KEYWORDS: [&str; 70] = [
    "abstract", "and", "array", "as", "bool", "break", "callable", "case", "catch", "class", "clone", "const",
    "continue", "declare", "default", "do", "echo", "else", "elseif", "empty", "enddeclare", "endfor",
    "endforeach", "endif", "endswitch", "endwhile", "enum", "extends", "false", "final", "finally", "float", "fn",
    "for", "foreach", "function", "global", "goto", "if", "implements", "instanceof", "insteadof", "int",
    "interface", "isset", "iterable", "list", "match", "mixed", "namespace", "never", "new", "null", "object",
    "or", "parent", "print", "private", "protected", "public", "readonly", "return", "self", "static", "string",
    "switch", "throw", "trait", "true", "try",
];

const MORE_KEYWORDS: [&str; 6] = ["unset", "use", "var", "void", "while", "yield"];

fn is_keyword(lower: &str) -> bool {
    KEYWORDS.contains(&lower) || MORE_KEYWORDS.contains(&lower)
}

/// What a file's text declares and names.
#[must_use]
pub fn read(text: &[u8]) -> FileNames {
    let found = tokens(text);
    let mut reader = Reader {
        tokens: &found,
        namespace: String::new(),
        imports: BTreeMap::new(),
        depth: 0,
        class_depth: None,
        out: FileNames::default(),
    };
    reader.run();
    reader.out
}

struct Reader<'a> {
    tokens: &'a [Token],
    namespace: String,
    imports: BTreeMap<String, String>,
    depth: usize,
    class_depth: Option<usize>,
    out: FileNames,
}

impl<'a> Reader<'a> {
    fn at(&self, index: usize) -> Option<&'a Token> {
        self.tokens.get(index)
    }

    fn lower_at(&self, index: usize) -> Option<String> {
        match self.at(index) {
            Some(Token::Name(name)) => Some(name.to_ascii_lowercase()),
            _ => None,
        }
    }

    fn unplace(&mut self, why: &'static str) {
        self.out.unplaced.get_or_insert(why);
    }

    fn run(&mut self) {
        let mut index = 0;

        while index < self.tokens.len() {
            index = self.step(index);
        }
    }

    fn step(&mut self, index: usize) -> usize {
        let before = index.checked_sub(1).and_then(|at| self.at(at)).cloned();

        match self.at(index) {
            Some(Token::Punct(b'{')) => self.depth += 1,
            Some(Token::Punct(b'}')) => {
                self.depth = self.depth.saturating_sub(1);
                if self.class_depth.is_some_and(|depth| depth > self.depth) {
                    self.class_depth = None;
                }
            }
            Some(Token::Variable) => self.variable(index, before.as_ref()),
            Some(Token::Name(name)) => return self.name(index, &name.clone(), before.as_ref()),
            Some(Token::Quoted(text)) => self.spelt(text),
            _ => {}
        }

        index + 1
    }

    fn variable(&mut self, index: usize, before: Option<&Token>) {
        let next = self.at(index + 1);

        if next == Some(&Token::Punct(b'(')) && before != Some(&Token::Arrow) {
            self.unplace("a call by a name held in a variable");
        }

        if next == Some(&Token::DoubleColon) {
            self.unplace("a class named by a variable");
        }

        if before == Some(&Token::DoubleColon) && next == Some(&Token::Punct(b'(')) {
            self.unplace("a static method named by a variable");
        }
    }

    fn name(&mut self, index: usize, name: &str, before: Option<&Token>) -> usize {
        let lower = name.to_ascii_lowercase();
        let next = self.at(index + 1).cloned();

        if before == Some(&Token::Arrow) && next == Some(Token::Punct(b'(')) && RESOLVERS.contains(&lower.as_str()) {
            self.resolver(index + 2);
        }

        if matches!(before, Some(Token::Arrow | Token::DoubleColon)) {
            self.mention(name);
            return index + 1;
        }

        match lower.as_str() {
            "namespace" => return self.namespace(index + 1),
            "use" if self.class_depth.is_none() => return self.import(index + 1),
            "function" | "fn" => return self.declared_function(index + 1, &lower),
            "const" => self.declared_constants(index + 1),
            "new" => self.new_at(index + 1),
            word if DECLARING.contains(&word) => return self.declared_class(index + 1),
            word if INCLUDES.contains(&word) => self.included(index + 1),
            word if DYNAMIC_CALLS.contains(&word) && next == Some(Token::Punct(b'(')) => {
                self.unplace("a call by a name held as it runs");
            }
            "app" | "resolve" if next == Some(Token::Punct(b'(')) => self.resolver(index + 2),
            "define" if next == Some(Token::Punct(b'(')) => self.defined(index + 2),
            _ => {}
        }

        if REFLECTIONS.iter().any(|reflection| self.resolved(name).ends_with(reflection)) {
            self.unplace("a reflection");
        }

        self.mention(name);

        index + 1
    }

    /// Every name a name written here may stand for, as the gate's own name
    /// graph reads it: as resolved against the namespace and the imports, as
    /// written where it is not fully qualified, and as a constant by its last
    /// segment.
    fn mention(&mut self, name: &str) {
        let lower = name.to_ascii_lowercase();
        let last = lower.rsplit('\\').next().unwrap_or_default();
        self.out.names.insert(format!("{CONSTANT}{last}"));

        if is_keyword(&lower) {
            return;
        }

        self.out.names.insert(self.resolved(name));

        if !lower.starts_with('\\') && !lower.starts_with("namespace\\") {
            self.out.names.insert(lower);
        }
    }

    /// Every fully qualified name a quoted string spells, as a class-string does: two or more segments joined by
    /// backslashes, single or doubled, with or without a leading one.
    fn spelt(&mut self, text: &str) {
        let text = text.replace("\\\\", "\\");
        let bytes = text.as_bytes();
        let mut at = 0;

        while at < bytes.len() {
            match qualified_at(bytes, at) {
                Some(end) => {
                    let found = text.get(at..end).unwrap_or_default().trim_start_matches('\\');
                    self.out.names.insert(found.to_ascii_lowercase());
                    at = end;
                }
                None => at += 1,
            }
        }
    }

    /// Each constant a `const` declares, by its last segment, up to the statement's end.
    fn declared_constants(&mut self, mut index: usize) {
        while let Some(token) = self.at(index) {
            match (token, self.at(index + 1)) {
                (Token::Punct(b';'), _) => return,
                (Token::Name(name), Some(Token::Punct(b'='))) => {
                    let declared = format!("{CONSTANT}{}", name.to_ascii_lowercase());
                    self.out.declares.insert(declared);
                }
                _ => {}
            }
            index += 1;
        }
    }

    /// The constant a `define()` declares by a quoted name, by its last segment.
    fn defined(&mut self, argument: usize) {
        if let Some(Token::Quoted(name)) = self.at(argument) {
            let last = name.rsplit('\\').next().unwrap_or_default().to_ascii_lowercase();
            self.out.declares.insert(format!("{CONSTANT}{last}"));
        }
    }

    fn new_at(&mut self, index: usize) {
        match self.at(index) {
            Some(Token::Variable | Token::Punct(b'(')) => self.unplace("a class built from a value"),
            _ => {}
        }
    }

    /// An include whose path holds a variable or a string PHP fills in as it runs cannot be placed.
    fn included(&mut self, mut index: usize) {
        while let Some(token) = self.at(index) {
            match token {
                Token::Punct(b';') => return,
                Token::Variable | Token::Str { literal: false } => {
                    self.unplace("an include of a path built as it runs");
                    return;
                }
                _ => index += 1,
            }
        }
    }

    fn resolver(&mut self, argument: usize) {
        if matches!(self.at(argument), Some(Token::Variable | Token::Str { literal: false })) {
            self.unplace("a container read by a key held as it runs");
        }
    }

    fn namespace(&mut self, mut index: usize) -> usize {
        let named = match self.at(index) {
            Some(Token::Name(name)) => {
                index += 1;
                name.clone()
            }
            _ => String::new(),
        };
        self.namespace = named.trim_start_matches('\\').to_ascii_lowercase();
        self.imports.clear();

        if !named.is_empty() {
            self.mention(&named);
        }

        index
    }

    fn import(&mut self, mut index: usize) -> usize {
        let mut prefix = String::new();
        let mut current: Option<String> = None;
        let mut alias: Option<String> = None;

        if matches!(self.lower_at(index).as_deref(), Some("function" | "const")) {
            index += 1;
        }

        while let Some(token) = self.at(index) {
            match token {
                Token::Punct(b';') => {
                    self.imported(&prefix, current.take(), alias.take());
                    return index + 1;
                }
                Token::Punct(b',') => self.imported(&prefix, current.take(), alias.take()),
                Token::Punct(b'{') => prefix = current.take().unwrap_or_default(),
                Token::Punct(b'}') => self.imported(&prefix, current.take(), alias.take()),
                Token::Name(name) if name.eq_ignore_ascii_case("as") => {}
                Token::Name(name) if current.is_some() && self.lower_at(index - 1).as_deref() == Some("as") => {
                    alias = Some(name.to_ascii_lowercase());
                }
                Token::Name(name) => current = Some(name.trim_start_matches('\\').trim_end_matches('\\').to_owned()),
                Token::Punct(b'\\') => {}
                _ => return index,
            }
            index += 1;
        }

        index
    }

    fn imported(&mut self, prefix: &str, name: Option<String>, alias: Option<String>) {
        let Some(name) = name else {
            return;
        };
        let full = if prefix.is_empty() { name } else { format!("{prefix}\\{name}") }.to_ascii_lowercase();
        let short = alias.unwrap_or_else(|| full.rsplit('\\').next().unwrap_or_default().to_owned());
        self.out.names.insert(self.qualified(&full));
        self.mention(&full);
        self.out.names.insert(full.clone());
        self.imports.insert(short, full);
    }

    fn declared_class(&mut self, index: usize) -> usize {
        if let Some(Token::Name(name)) = self.at(index) {
            let declared = self.qualified(&name.to_ascii_lowercase());
            self.mention(name);
            self.out.declares.insert(declared);
            self.class_depth = Some(self.depth + 1);
            return index + 1;
        }

        index
    }

    fn declared_function(&mut self, index: usize, keyword: &str) -> usize {
        let index = if self.at(index) == Some(&Token::Punct(b'&')) { index + 1 } else { index };

        match self.at(index) {
            Some(Token::Name(name)) => {
                let lower = name.to_ascii_lowercase();
                self.mention(name);
                if keyword == "function" && self.class_depth.is_some() && MAGIC.contains(&lower.as_str()) {
                    self.unplace("a magic method that answers any name");
                }
                if keyword == "function" && self.class_depth.is_none() {
                    self.out.declares.insert(self.qualified(&lower));
                }
                index + 1
            }
            _ => index,
        }
    }

    fn qualified(&self, lower: &str) -> String {
        if self.namespace.is_empty() {
            lower.to_owned()
        } else {
            format!("{}\\{lower}", self.namespace)
        }
    }

    /// A name as PHP resolves it in this file, lower-cased.
    fn resolved(&self, name: &str) -> String {
        if let Some(full) = name.strip_prefix('\\') {
            return full.to_ascii_lowercase();
        }

        let lower = name.to_ascii_lowercase();

        if let Some(rest) = lower.strip_prefix("namespace\\") {
            return self.qualified(rest);
        }

        let (first, rest) = lower.split_once('\\').map_or((lower.as_str(), None), |(first, rest)| (first, Some(rest)));

        match (self.imports.get(first), rest) {
            (Some(full), Some(rest)) => format!("{full}\\{rest}"),
            (Some(full), None) => full.clone(),
            (None, _) => self.qualified(&lower),
        }
    }
}

/// Where a fully qualified name a string spells starting at a byte ends: one
/// that matches `\\?[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)+` there,
/// as long as it runs; none where none starts there.
fn qualified_at(bytes: &[u8], start: usize) -> Option<usize> {
    let mut at = start + usize::from(bytes.get(start) == Some(&b'\\'));
    at = segment_at(bytes, at)?;
    let mut segments = 1;

    while bytes.get(at) == Some(&b'\\') {
        match segment_at(bytes, at + 1) {
            Some(end) => {
                at = end;
                segments += 1;
            }
            None => break,
        }
    }

    (segments > 1).then_some(at)
}

/// Where a segment of a name starting at a byte ends; none where none starts there.
fn segment_at(bytes: &[u8], start: usize) -> Option<usize> {
    let first = *bytes.get(start)?;

    if !(first.is_ascii_alphabetic() || first == b'_') {
        return None;
    }

    let mut at = start + 1;

    while bytes.get(at).is_some_and(|byte| byte.is_ascii_alphanumeric() || *byte == b'_') {
        at += 1;
    }

    Some(at)
}

#[cfg(test)]
mod tests {
    use super::read;

    #[test]
    fn resolves_names_against_the_namespace_and_imports() {
        let found = read(
            b"<?php namespace App\\Billing; use App\\Money\\{Money, Currency as Cur}; use function App\\helper;\n\
              final class Invoice extends Base implements \\Countable { use Totals; public function total(Cur $c): Money { return helper(Rate::of(new Line())); } }",
        );

        assert!(found.declares.contains("app\\billing\\invoice"));
        for name in ["app\\money\\money", "app\\money\\currency", "app\\billing\\base", "countable", "app\\billing\\totals", "app\\billing\\rate", "app\\billing\\line", "app\\helper"] {
            assert!(found.names.contains(name), "{name} in {:?}", found.names);
        }
        assert_eq!(found.unplaced, None);
    }

    #[test]
    fn marks_what_it_cannot_place() {
        for (text, why) in [
            ("<?php $f();", "a call by a name held in a variable"),
            ("<?php new $class;", "a class built from a value"),
            ("<?php $class::make();", "a class named by a variable"),
            ("<?php call_user_func($f);", "a call by a name held as it runs"),
            ("<?php require $path;", "an include of a path built as it runs"),
            ("<?php class A { public function __get($n) {} }", "a magic method that answers any name"),
            ("<?php $c->make($id);", "a container read by a key held as it runs"),
            ("<?php new \\ReflectionClass($x);", "a reflection"),
        ] {
            assert_eq!(read(text.as_bytes()).unplaced, Some(why), "{text}");
        }
    }

    #[test]
    fn reads_constants_class_strings_and_members_as_the_gate_does() {
        let found = read(
            b"<?php namespace App; const LIMIT = 1; define('App\\\\RATE', 2); class Bag { const int SIZE = 3, MORE = 4; }\n\
              $x = 'App\\\\Fake\\\\Clock'; $y = \"\\\\Other\\\\Thing x-y\\\\z\"; 'Single'; $w->total(); Rate::MAX;",
        );

        for name in ["const limit", "const rate", "const size", "const more"] {
            assert!(found.declares.contains(name), "{name} in {:?}", found.declares);
        }
        for name in ["app\\fake\\clock", "other\\thing", "y\\z", "total", "app\\total", "const max", "app\\rate", "app"] {
            assert!(found.names.contains(name), "{name} in {:?}", found.names);
        }
        assert!(!found.names.contains("single"));
    }

    #[test]
    fn places_literal_includes_and_method_calls() {
        assert_eq!(read(b"<?php require __DIR__ . 'x.php'; require 'y.php'; $a->b(); A::c();").unplaced, None);
    }
}
