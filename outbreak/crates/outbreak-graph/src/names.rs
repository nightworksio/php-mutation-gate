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

        if before == Some(&Token::Arrow) {
            if next == Some(Token::Punct(b'(')) && RESOLVERS.contains(&lower.as_str()) {
                self.resolver(index + 2);
            }
            return index + 1;
        }

        if before == Some(&Token::DoubleColon) {
            return index + 1;
        }

        match lower.as_str() {
            "namespace" => return self.namespace(index + 1),
            "use" if self.class_depth.is_none() => return self.import(index + 1),
            "function" | "fn" | "const" => return self.declared_function(index + 1, &lower),
            "new" => self.new_at(index + 1),
            word if DECLARING.contains(&word) && !matches!(before, Some(Token::DoubleColon)) => {
                return self.declared_class(index + 1);
            }
            word if INCLUDES.contains(&word) => self.included(index + 1),
            word if DYNAMIC_CALLS.contains(&word) && next == Some(Token::Punct(b'(')) => {
                self.unplace("a call by a name held as it runs");
            }
            "app" | "resolve" if next == Some(Token::Punct(b'(')) => self.resolver(index + 2),
            word if is_keyword(word) => {}
            _ => {
                let resolved = self.resolved(name);
                if REFLECTIONS.iter().any(|reflection| resolved.ends_with(reflection)) {
                    self.unplace("a reflection");
                }
                self.out.names.insert(resolved);
                if next == Some(Token::Punct(b'(')) && !name.contains('\\') && !self.namespace.is_empty() {
                    self.out.names.insert(lower.clone());
                }
            }
        }

        index + 1
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
        self.namespace = match self.at(index) {
            Some(Token::Name(name)) => {
                index += 1;
                name.trim_start_matches('\\').to_ascii_lowercase()
            }
            _ => String::new(),
        };
        self.imports.clear();

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
        self.out.names.insert(full.clone());
        self.imports.insert(short, full);
    }

    fn declared_class(&mut self, index: usize) -> usize {
        if let Some(Token::Name(name)) = self.at(index) {
            let declared = self.qualified(&name.to_ascii_lowercase());
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
    fn places_literal_includes_and_method_calls() {
        assert_eq!(read(b"<?php require __DIR__ . 'x.php'; require 'y.php'; $a->b(); A::c();").unplaced, None);
    }
}
